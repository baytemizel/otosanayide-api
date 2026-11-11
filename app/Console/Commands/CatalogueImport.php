<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\Contact;
use App\Domain\Catalogue\SearchProjection;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * packages/server/catalogue-import.ts. Varsayilan kuru calisma veritabanina hic baglanmaz.
 * --batch=N kaynakta yoktur (K194): ayni satirlari N'lik gruplarla, grup basina tek transaction ile yazar.
 * Uzak veritabaninda satir basina ~20 istek yerine grup basina ~15 istek gider; ustune yazmama kurali aynidir.
 */
final class CatalogueImport extends Command
{
    public const MAX_BATCH = 2000;

    /** MySQL hazir ifade siniri 65.535 yer tutucudur; en genis ekleme 15 kolondur. */
    private const INSERT_ROWS = 1000;

    /** Tek ifadedeki JSON yuku; sunucunun max_allowed_packet degerinin cok altinda tutulur. */
    private const INSERT_BYTES = 4_000_000;

    protected $signature = 'catalogue:import {path : JSONL dosyasi} {--apply : Veritabanina yazar} {--confirm-count= : --apply icin beklenen satir sayisi} {--batch=0 : --apply icin grup boyutu (1-2000); 0 satir satir yazar}';

    protected $description = 'Kaynak JSONL katalogu dogrular; --apply olmadan yazmaz. Kayitlar published=false olusur, ustune yazilmaz.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_readable($path)) {
            $this->error('Dosya okunamadi.');

            return self::FAILURE;
        }
        try {
            $text = str_ends_with($path, '.gz') ? gzdecode((string) file_get_contents($path)) : file_get_contents($path);
            $entries = self::prepare((string) $text);
        } catch (RuntimeException|ZodError $e) {
            $this->error($e instanceof ZodError ? 'Satir dogrulanamadi: '.$e->issue : $e->getMessage());

            return self::FAILURE;
        }
        $this->info('Dogrulanan satir: '.count($entries));
        if (! $this->option('apply')) {
            $this->info('Kuru calisma: veritabanina baglanilmadi, hicbir sey yazilmadi.');

            return self::SUCCESS;
        }
        if ((string) $this->option('confirm-count') !== (string) count($entries)) {
            $this->error('--apply icin --confirm-count satir sayisiyla ayni olmali.');

            return self::FAILURE;
        }
        $batch = (string) $this->option('batch');
        if (preg_match('/^(0|[1-9][0-9]{0,3})$/', $batch) !== 1 || (int) $batch > self::MAX_BATCH) {
            $this->error('--batch 0 ile '.self::MAX_BATCH.' arasinda bir tam sayi olmali.');

            return self::FAILURE;
        }
        $counts = ['created' => 0, 'skipped' => 0];
        try {
            if ((int) $batch === 0) {
                foreach ($entries as $entry) {
                    $counts[Tx::run(fn () => $this->importOne($entry))]++;
                }
            } else {
                foreach (array_chunk($entries, (int) $batch) as $index => $chunk) {
                    $done = Tx::run(fn () => self::importChunk($chunk));
                    $counts['created'] += $done['created'];
                    $counts['skipped'] += $done['skipped'];
                    if (($index + 1) % 10 === 0) {
                        $this->line("Ilerleme: olusan {$counts['created']}, atlanan {$counts['skipped']}");
                    }
                }
            }
        } catch (QueryException $e) {
            // Laravel iletisi SQL'i bagli degerlerle (gercek isletme verisi) basar; yalniz surucu iletisi yazilir.
            $this->error('Veritabani hatasi: '.($e->getPrevious()?->getMessage() ?? 'bilinmiyor'));
            $this->error("Durduruldu. Olusan: {$counts['created']}, atlanan: {$counts['skipped']}");

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->error("Durduruldu. Olusan: {$counts['created']}, atlanan: {$counts['skipped']}");

            return self::FAILURE;
        }
        $this->info("Olusan: {$counts['created']}, atlanan: {$counts['skipped']}");

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    public static function prepare(string $text): array
    {
        $schema = Z::object([
            'id' => Z::string()->regex('/^[a-f0-9]{24}$/'),
            'candidateId' => Z::string()->regex('/^[a-f0-9]{24}$/'),
            'sourceRow' => Z::number()->int()->nonnegative(),
            'name' => Z::string()->max(1000),
            'city' => Z::string()->max(100),
            'district' => Z::string()->max(500),
            'address' => Z::string()->max(5000),
            'phone' => Z::string()->nullable(),
            'contactEmail' => Z::string()->max(254)->refine(static fn (string $v): bool => $v === '' || Contact::businessEmail($v) !== null)->nullable()->optional(),
            'website' => Z::string()->nullable(),
            'categoryCandidates' => Z::array(Z::string()),
            'specialtyCandidates' => Z::array(Z::string()),
            'flags' => Z::array(Z::string()),
            'rawSource' => Z::record(Z::string(), Z::any()),
        ], 'passthrough');
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $seen = [];
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $index => $line) {
            if (Js::trim($line) === '') {
                continue;
            }
            if (strlen($line) > 250000) {
                throw new RuntimeException('Oversized source row '.($index + 1));
            }
            $decoded = json_decode($line, false, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON at row '.($index + 1));
            }
            $source = $schema->parse($decoded);
            if (isset($seen[$source['id']])) {
                throw new RuntimeException('Duplicate source id at row '.($index + 1));
            }
            $seen[$source['id']] = true;
            $out[] = ['businessId' => 'import-'.$source['id'], 'sourceHash' => hash('sha256', self::canonical($source)), 'source' => $source];
        }

        return $out;
    }

    private static function canonical(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value) && array_is_list($value) && $value !== []) {
            return '['.implode(',', array_map([self::class, 'canonical'], $value)).']';
        }
        if (is_array($value)) {
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = json_encode((string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).':'.self::canonical($v);
            }

            return '{'.implode(',', $parts).'}';
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $entry */
    private function importOne(array $entry): string
    {
        $s = $entry['source'];
        $b = DB::table('businesses')->where('id', $entry['businessId'])->lockForUpdate()->first();
        $e = DB::table('catalogue_sources')->where('id', $s['id'])->lockForUpdate()->first();
        if ($b !== null || $e !== null) {
            if ($b?->import_source_id === $s['id'] && $e?->source_hash === $entry['sourceHash'] && $e?->business_id === $entry['businessId']) {
                return 'skipped';
            }
            throw new RuntimeException('Import conflict: existing records will not be overwritten');
        }
        DB::table('businesses')->insert(self::businessRow($entry));
        DB::table('catalogue_sources')->insert(self::sourceRow($entry));
        foreach (self::valueRows($entry) as $row) {
            DB::table('catalogue_source_values')->insert($row);
        }
        DB::table('business_history')->insert(self::historyRow($entry));
        SearchProjection::sync($entry['businessId']);

        return 'created';
    }

    /**
     * importOne ile ayni satirlari grup halinde yazar; cagiran tek transaction acar, hata grubun tamamini geri alir.
     * Yeni kayit yayimlanmamis ve koordinati dogrulanmamis oldugundan arama belgesi olusmaz
     * (BusinessReader::indexDocument null doner); bu yuzden SearchProjection::sync cagrilmaz.
     *
     * @param  list<array<string,mixed>>  $chunk
     * @return array{created:int,skipped:int}
     */
    public static function importChunk(array $chunk): array
    {
        $businesses = DB::table('businesses')->whereIn('id', array_column($chunk, 'businessId'))->lockForUpdate()->get(['id', 'import_source_id'])->keyBy('id');
        $sources = DB::table('catalogue_sources')->whereIn('id', array_map(static fn (array $e): string => $e['source']['id'], $chunk))->lockForUpdate()->get(['id', 'business_id', 'source_hash'])->keyBy('id');
        $fresh = [];
        $skipped = 0;
        foreach ($chunk as $entry) {
            $id = $entry['source']['id'];
            $b = $businesses->get($entry['businessId']);
            $e = $sources->get($id);
            if ($b === null && $e === null) {
                $fresh[] = $entry;

                continue;
            }
            if ($b?->import_source_id === $id && $e?->source_hash === $entry['sourceHash'] && $e?->business_id === $entry['businessId']) {
                $skipped++;

                continue;
            }
            throw new RuntimeException('Import conflict: existing records will not be overwritten');
        }
        self::insertRows('businesses', array_map([self::class, 'businessRow'], $fresh));
        self::insertRows('catalogue_sources', array_map([self::class, 'sourceRow'], $fresh));
        self::insertRows('catalogue_source_values', array_merge(...array_map([self::class, 'valueRows'], $fresh)));
        self::insertRows('business_history', array_map([self::class, 'historyRow'], $fresh));

        return ['created' => count($fresh), 'skipped' => $skipped];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function insertRows(string $table, array $rows): void
    {
        $part = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $size = array_sum(array_map(static fn (mixed $v): int => is_string($v) ? strlen($v) : 8, $row));
            if ($part !== [] && (count($part) >= self::INSERT_ROWS || $bytes + $size > self::INSERT_BYTES)) {
                DB::table($table)->insert($part);
                $part = [];
                $bytes = 0;
            }
            $part[] = $row;
            $bytes += $size;
        }
        if ($part !== []) {
            DB::table($table)->insert($part);
        }
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private static function businessRow(array $entry): array
    {
        $s = $entry['source'];

        return [
            'id' => $entry['businessId'], 'name' => $s['name'], 'city' => $s['city'], 'district' => $s['district'], 'address' => $s['address'],
            'phone' => $s['phone'] ?? '', 'contact_email' => ! empty($s['contactEmail']) ? Contact::businessEmail($s['contactEmail']) : null, 'website' => $s['website'] ?? '', 'description' => '',
            'published' => false, 'sample' => false, 'ownership' => 'unclaimed', 'coordinate_status' => 'unverified', 'import_source_id' => $s['id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private static function sourceRow(array $entry): array
    {
        $s = $entry['source'];
        $known = ['id', 'candidateId', 'sourceRow', 'name', 'city', 'district', 'address', 'phone', 'contactEmail', 'website', 'categoryCandidates', 'specialtyCandidates', 'flags', 'rawSource'];
        $extra = array_diff_key($s, array_flip($known));

        return [
            'id' => $s['id'], 'business_id' => $entry['businessId'], 'candidate_id' => $s['candidateId'], 'source_row' => $s['sourceRow'], 'source_hash' => $entry['sourceHash'],
            'name' => $s['name'], 'city' => $s['city'], 'district' => $s['district'], 'address' => $s['address'], 'phone' => $s['phone'], 'contact_email' => $s['contactEmail'] ?? null, 'website' => $s['website'],
            'raw_source' => json_encode($s['rawSource'], JSON_UNESCAPED_UNICODE), 'extra' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return list<array<string,mixed>>
     */
    private static function valueRows(array $entry): array
    {
        $rows = [];
        foreach (['category' => 'categoryCandidates', 'specialty' => 'specialtyCandidates', 'flag' => 'flags'] as $kind => $field) {
            foreach ($entry['source'][$field] as $i => $value) {
                $rows[] = ['source_id' => $entry['source']['id'], 'kind' => $kind, 'position' => $i, 'value' => $value];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private static function historyRow(array $entry): array
    {
        return ['business_id' => $entry['businessId'], 'id' => 'source-import', 'kind' => 'source_import', 'details' => json_encode(['sourceId' => $entry['source']['id'], 'sourceHash' => $entry['sourceHash']])];
    }
}
