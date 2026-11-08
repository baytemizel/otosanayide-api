<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Kendi basina hicbir sey yazmaz: uretimde `db:seed` yanlislikla veri olusturmasin. */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Varsayilan seeder bos. --class=AdminAccountSeeder veya --class=LocalFixtureSeeder kullanin.');
    }
}
