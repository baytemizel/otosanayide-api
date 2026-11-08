<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\Contact;
use App\Domain\Catalogue\SearchProjection;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/** packages/server/catalogue-import.ts. Varsayilan kuru calisma veritabanina hic baglanmaz. */
final class CatalogueImport extends Command
{
    protected $signature = 'catalogue:import {path : JSONL dosyasi} {--apply : Veritabanina yazar} {--confirm-count= : --apply icin beklenen satir sayisi}';

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
        $counts = ['created' => 0, 'skipped' => 0];
        foreach ($entries as $entry) {
            $counts[Tx::run(fn () => $this->importOne($entry))]++;
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
        $known = ['id', 'candidateId', 'sourceRow', 'name', 'city', 'district', 'address', 'phone', 'contactEmail', 'website', 'categoryCandidates', 'specialtyCandidates', 'flags', 'rawSource'];
        $extra = array_diff_key($s, array_flip($known));
        $email = ! empty($s['contactEmail']) ? Contact::businessEmail($s['contactEmail']) : null;
        DB::table('businesses')->insert([
            'id' => $entry['businessId'], 'name' => $s['name'], 'city' => $s['city'], 'district' => $s['district'], 'address' => $s['address'],
            'phone' => $s['phone'] ?? '', 'contact_email' => $email, 'website' => $s['website'] ?? '', 'description' => '',
            'published' => false, 'sample' => false, 'ownership' => 'unclaimed', 'coordinate_status' => 'unverified', 'import_source_id' => $s['id'],
        ]);
        DB::table('catalogue_sources')->insert([
            'id' => $s['id'], 'business_id' => $entry['businessId'], 'candidate_id' => $s['candidateId'], 'source_row' => $s['sourceRow'], 'source_hash' => $entry['sourceHash'],
            'name' => $s['name'], 'city' => $s['city'], 'district' => $s['district'], 'address' => $s['address'], 'phone' => $s['phone'], 'contact_email' => $s['contactEmail'] ?? null, 'website' => $s['website'],
            'raw_source' => json_encode($s['rawSource'], JSON_UNESCAPED_UNICODE), 'extra' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
        ]);
        foreach (['category' => 'categoryCandidates', 'specialty' => 'specialtyCandidates', 'flag' => 'flags'] as $kind => $field) {
            foreach ($s[$field] as $i => $value) {
                DB::table('catalogue_source_values')->insert(['source_id' => $s['id'], 'kind' => $kind, 'position' => $i, 'value' => $value]);
            }
        }
        DB::table('business_history')->insert(['business_id' => $entry['businessId'], 'id' => 'source-import', 'kind' => 'source_import', 'details' => json_encode(['sourceId' => $s['id'], 'sourceHash' => $entry['sourceHash']])]);
        SearchProjection::sync($entry['businessId']);

        return 'created';
    }
}
