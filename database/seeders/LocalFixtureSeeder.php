<?php

namespace Database\Seeders;

use App\Domain\Catalogue\SearchProjection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Yalniz local/testing: sentetik isletmeler. Gercek isletme verisi icermez; uretimde calismaz. */
final class LocalFixtureSeeder extends Seeder
{
    private const ROWS = [
        ['fixture-motor', 'Sentetik Motor Atölyesi', 'İstanbul', 'Ümraniye', 41.02, 29.11, 'mechanical', ['Fren', 'Motor bakımı'], ['BMW']],
        ['fixture-kaporta', 'Sentetik Kaporta Merkezi', 'İstanbul', 'Kartal', 40.89, 29.19, 'body', ['Kaporta', 'Boya'], []],
        ['fixture-lastik', 'Sentetik Lastik Noktası', 'Ankara', 'Çankaya', 39.91, 32.85, 'tyres', ['Lastik', 'Rot balans'], ['Fiat']],
        ['fixture-klima', 'Sentetik Klima Servisi', 'İzmir', 'Bornova', 38.46, 27.21, 'climate', ['Klima bakımı'], []],
    ];

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('LocalFixtureSeeder yalniz local ve testing ortaminda calisir.');
        }
        DB::transaction(function (): void {
            foreach (self::ROWS as [$id, $name, $city, $district, $lat, $lng, $category, $services, $brands]) {
                DB::table('businesses')->updateOrInsert(['id' => $id], ['name' => $name, 'city' => $city, 'district' => $district, 'address' => 'Sentetik Sanayi Sitesi No 1 '.$district, 'phone' => '', 'website' => '', 'description' => '', 'latitude' => $lat, 'longitude' => $lng, 'coordinate_status' => 'verified', 'published' => true, 'sample' => false, 'ownership' => 'unclaimed']);
                DB::table('business_values')->where('business_id', $id)->delete();
                $rows = [['business_id' => $id, 'kind' => 'category', 'position' => 0, 'value' => $category]];
                foreach ($services as $i => $v) {
                    $rows[] = ['business_id' => $id, 'kind' => 'service', 'position' => $i, 'value' => $v];
                }
                foreach ($brands as $i => $v) {
                    $rows[] = ['business_id' => $id, 'kind' => 'brand', 'position' => $i, 'value' => $v];
                }
                DB::table('business_values')->insert($rows);
                SearchProjection::sync($id);
            }
        });
        $this->command?->info('Sentetik isletmeler yazildi: '.count(self::ROWS));
    }
}
