<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * K194: catalogue:import --batch. Surec ici SQLite bellek veritabani; MySQL kabulu DEGILDIR.
 * Toplu yazim, satir satir yazimla ayni satirlari uretmeli ve ustune yazmama kuralini korumalidir.
 */
function catalogueDb(): void
{
    r2Db();
    $s = Schema::connection('r2');
    $s->create('catalogue_sources', fn (Blueprint $t) => [
        $t->string('id')->primary(), $t->string('business_id')->unique(), $t->string('candidate_id'), $t->integer('source_row'), $t->string('source_hash'),
        $t->string('name'), $t->string('city'), $t->string('district'), $t->text('address'), $t->text('phone')->nullable(), $t->string('contact_email')->nullable(), $t->text('website')->nullable(),
        $t->text('raw_source'), $t->text('extra')->nullable(),
    ]);
    $s->create('catalogue_source_values', fn (Blueprint $t) => [$t->string('source_id'), $t->string('kind'), $t->integer('position'), $t->text('value'), $t->primary(['source_id', 'kind', 'position'])]);
    $s->create('business_history', fn (Blueprint $t) => [$t->string('business_id'), $t->string('id'), $t->string('kind'), $t->text('details')->nullable(), $t->primary(['business_id', 'id'])]);
    if (! $s->hasTable('business_search')) {
        $s->create('business_search', fn (Blueprint $t) => [$t->string('business_id')->primary()]);
    }
}

/** @param array<string,mixed> $over */
function catalogueLine(int $n, array $over = []): string
{
    return (string) json_encode($over + [
        'id' => sprintf('%024x', $n), 'candidateId' => sprintf('%024x', 1000 + $n), 'sourceRow' => $n,
        'name' => "Örnek Servis {$n}", 'city' => 'Ankara', 'district' => 'Çankaya', 'address' => "Sentetik Sokak No {$n}",
        'phone' => $n % 2 === 0 ? '+903120000000' : null, 'website' => $n % 3 === 0 ? 'https://ornek.test' : null,
        'categoryCandidates' => $n % 2 === 0 ? ['mechanical', 'tyres'] : [], 'specialtyCandidates' => $n % 3 === 0 ? ['turbo'] : [], 'flags' => ['qc_google_unverified'],
        'rawSource' => ['title' => "Ham {$n}", 'nested' => ['a' => $n]],
        // Kaynaktaki yayin/sahiplik alanlari aktarilmamali; yalniz ozel kanitta (extra) kalir.
        'published' => true, 'ownership' => 'verified', 'coordinateStatus' => 'verified',
    ] + ($n % 4 === 0 ? ['contactEmail' => "servis{$n}@ornek.test"] : []), JSON_UNESCAPED_UNICODE);
}

/** @param list<string> $lines */
function catalogueFile(array $lines): string
{
    $file = (string) tempnam(sys_get_temp_dir(), 'cat');
    file_put_contents($file, implode("\n", $lines));

    return $file;
}

/** @return array<string,list<array<string,mixed>>> */
function catalogueSnapshot(): array
{
    $rows = static fn (string $table, array $order): array => array_map(static fn (object $r): array => (array) $r, DB::table($table)->orderBy($order[0])->orderBy($order[1])->orderBy($order[2])->get()->all());

    return [
        'businesses' => $rows('businesses', ['id', 'id', 'id']),
        'catalogue_sources' => $rows('catalogue_sources', ['id', 'id', 'id']),
        'catalogue_source_values' => $rows('catalogue_source_values', ['source_id', 'kind', 'position']),
        'business_history' => $rows('business_history', ['business_id', 'id', 'id']),
        'business_values' => $rows('business_values', ['business_id', 'kind', 'position']),
        'business_search' => $rows('business_search', ['business_id', 'business_id', 'business_id']),
    ];
}

it('writes the same rows in batch mode as row by row', function () {
    $file = catalogueFile(array_map(fn (int $n) => catalogueLine($n), range(1, 7)));

    catalogueDb();
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '7'])->expectsOutputToContain('Olusan: 7, atlanan: 0')->assertSuccessful();
    $rowByRow = catalogueSnapshot();

    catalogueDb();
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '7', '--batch' => '3'])->expectsOutputToContain('Olusan: 7, atlanan: 0')->assertSuccessful();
    $batched = catalogueSnapshot();
    unlink($file);

    expect($batched)->toEqual($rowByRow)
        ->and($batched['businesses'])->toHaveCount(7)
        ->and($batched['catalogue_source_values'])->toHaveCount(7 + 3 * 2 + 2)
        ->and($batched['business_values'])->toBe([])
        ->and($batched['business_search'])->toBe([]);
    foreach ($batched['businesses'] as $b) {
        expect((int) $b['published'])->toBe(0)->and($b['ownership'])->toBe('unclaimed')->and($b['coordinate_status'])->toBe('unverified')
            ->and($b['latitude'])->toBeNull()->and($b['owner_uid'])->toBeNull();
    }
    expect($batched['businesses'][3]['id'])->toBe('import-'.sprintf('%024x', 4))->and($batched['businesses'][3]['contact_email'])->toBe('servis4@ornek.test')
        ->and(json_decode($batched['catalogue_sources'][0]['extra'], true))->toBe(['published' => true, 'ownership' => 'verified', 'coordinateStatus' => 'verified']);
});

it('skips identical rows and resumes after a partial batch import', function () {
    catalogueDb();
    $first = catalogueFile(array_map(fn (int $n) => catalogueLine($n), range(1, 3)));
    $all = catalogueFile(array_map(fn (int $n) => catalogueLine($n), range(1, 7)));
    $this->artisan('catalogue:import', ['path' => $first, '--apply' => true, '--confirm-count' => '3', '--batch' => '2'])->expectsOutputToContain('Olusan: 3, atlanan: 0')->assertSuccessful();
    DB::table('businesses')->where('id', 'import-'.sprintf('%024x', 2))->update(['description' => 'Yonetici duzenlemesi']);

    $this->artisan('catalogue:import', ['path' => $all, '--apply' => true, '--confirm-count' => '7', '--batch' => '5'])->expectsOutputToContain('Olusan: 4, atlanan: 3')->assertSuccessful();
    $this->artisan('catalogue:import', ['path' => $all, '--apply' => true, '--confirm-count' => '7', '--batch' => '2000'])->expectsOutputToContain('Olusan: 0, atlanan: 7')->assertSuccessful();
    unlink($first);
    unlink($all);

    expect(DB::table('businesses')->count())->toBe(7)->and(DB::table('catalogue_sources')->count())->toBe(7)->and(DB::table('business_history')->count())->toBe(7)
        ->and(DB::table('businesses')->where('id', 'import-'.sprintf('%024x', 2))->value('description'))->toBe('Yonetici duzenlemesi');
});

it('stops on a changed source without writing any row of that batch', function () {
    catalogueDb();
    $first = catalogueFile([catalogueLine(1), catalogueLine(2)]);
    $this->artisan('catalogue:import', ['path' => $first, '--apply' => true, '--confirm-count' => '2', '--batch' => '10'])->assertSuccessful();
    $changed = catalogueFile([catalogueLine(3), catalogueLine(2, ['name' => 'Degisen Ad']), catalogueLine(4)]);

    $this->artisan('catalogue:import', ['path' => $changed, '--apply' => true, '--confirm-count' => '3', '--batch' => '10'])
        ->expectsOutputToContain('Import conflict: existing records will not be overwritten')->assertFailed();
    unlink($first);
    unlink($changed);

    expect(DB::table('businesses')->orderBy('id')->pluck('id')->all())->toBe(['import-'.sprintf('%024x', 1), 'import-'.sprintf('%024x', 2)])
        ->and(DB::table('businesses')->where('id', 'import-'.sprintf('%024x', 2))->value('name'))->toBe('Örnek Servis 2')
        ->and(DB::table('catalogue_sources')->count())->toBe(2);
});

it('rejects an invalid batch size before writing', function (string $batch) {
    catalogueDb();
    $file = catalogueFile([catalogueLine(1)]);
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '1', '--batch' => $batch])->expectsOutputToContain('--batch 0 ile 2000 arasinda')->assertFailed();
    unlink($file);

    expect(DB::table('businesses')->count())->toBe(0);
})->with(['abc', '-1', '2001', '1.5', '']);

it('does not print bound row data when the database rejects a batch', function () {
    catalogueDb();
    Schema::connection('r2')->drop('business_history');
    $file = catalogueFile([catalogueLine(1), catalogueLine(2)]);
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '2', '--batch' => '10'])
        ->expectsOutputToContain('Veritabani hatasi:')->doesntExpectOutputToContain('Sentetik Sokak')->assertFailed();
    unlink($file);

    // Grup tek transaction'dir: gecmis tablosu hatasi isletme ve kaynak satirlarini da geri alir.
    expect(DB::table('businesses')->count())->toBe(0)->and(DB::table('catalogue_sources')->count())->toBe(0);
});
