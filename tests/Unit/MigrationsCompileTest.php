<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Baglanti kurmadan: migration'lar MySQL DDL'ine derlenir (pretend). Gercek motor kabulu DEGILDIR.
function compiledSql(): array
{
    $sql = [];
    foreach (collect(File::files(database_path('migrations')))->sortBy->getFilename() as $file) {
        $migration = require $file->getPathname();
        $log = DB::connection()->pretend(fn () => $migration->up());
        foreach ($log as $entry) {
            $sql[$file->getFilename()][] = $entry['query'];
        }
    }

    return $sql;
}

it('compiles every migration without a database connection', function () {
    $all = compiledSql();
    expect($all)->not->toBeEmpty();
    foreach ($all as $file => $statements) {
        expect($statements)->not->toBeEmpty();
    }
});

it('creates tables with the Turkish collation and InnoDB', function () {
    foreach (compiledSql() as $file => $statements) {
        foreach ($statements as $sql) {
            if (str_starts_with($sql, 'create table')) {
                expect($sql)->toContain('engine = InnoDB')->toContain('utf8mb4_tr_0900_ai_ci');
            }
        }
    }
});

it('keeps identity columns case sensitive', function () {
    $sql = implode("\n", compiledSql()['2026_11_08_000002_create_auth_tables.php']);
    expect($sql)->toContain("`id` varchar(128) character set utf8mb4 collate 'utf8mb4_bin'")
        ->toContain("`email` varchar(254) character set utf8mb4 collate 'utf8mb4_bin'");
});
