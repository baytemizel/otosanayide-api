<?php

use App\Domain\Catalogue\SearchProjection;
use Illuminate\Support\Facades\DB;

function seedBusiness(string $id, array $over = [], array $services = ['Fren'], array $brands = ['BMW'], array $cats = ['mechanical']): void
{
    DB::table('businesses')->insert($over + ['id' => $id, 'name' => 'Usta Motor '.$id, 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sanayi Sitesi No 12 Ümraniye', 'phone' => '', 'website' => '', 'description' => '', 'latitude' => 41.02, 'longitude' => 29.1, 'coordinate_status' => 'verified', 'published' => true]);
    foreach (['category' => $cats, 'service' => $services, 'brand' => $brands] as $kind => $values) {
        foreach ($values as $i => $value) {
            DB::table('business_values')->insert(['business_id' => $id, 'kind' => $kind, 'position' => $i, 'value' => $value]);
        }
    }
    SearchProjection::sync($id);
}

it('built the FULLTEXT index with stopwords off so short Turkish bigrams match', function () {
    seedBusiness('a1', ['name' => 'Ali İnşaat Oto']);
    $ids = collect($this->get('/api/search?q=in')->json('items'))->pluck('id');
    expect($ids)->toContain('a1');
});

it('stores latitude/longitude on the correct axes', function () {
    seedBusiness('a1');
    $row = DB::selectOne('SELECT ST_Latitude(location) lat, ST_Longitude(location) lng FROM business_search');
    expect(round($row->lat, 2))->toBe(41.02)->and(round($row->lng, 2))->toBe(29.1);
});

it('searches only published records with approved coordinates', function () {
    seedBusiness('p1');
    seedBusiness('p2', ['published' => false]);
    seedBusiness('p3', ['coordinate_status' => 'unverified']);
    seedBusiness('p4', ['sample' => true]);
    expect(collect($this->get('/api/search?city=İstanbul')->assertOk()->json('items'))->pluck('id')->all())->toBe(['p1']);
});

it('removes a record from search in the same transaction when unpublished', function () {
    seedBusiness('p1');
    DB::table('businesses')->where('id', 'p1')->update(['published' => false]);
    SearchProjection::sync('p1');
    expect(DB::table('business_search')->count())->toBe(0);
});

it('filters strictly by city, district, facets, and caps at 24', function () {
    foreach (range(1, 30) as $i) {
        seedBusiness('c'.$i);
    }
    seedBusiness('ank', ['city' => 'Ankara', 'district' => 'Çankaya']);
    $r = $this->get('/api/search?city=İstanbul&district=umraniye&category=mechanical&service=Fren&brand=BMW')->assertOk();
    contract('live-search', 'search', $r->json());
    expect($r->json('items'))->toHaveCount(24)->and($r->json('districts'))->toBe(['Ümraniye'])->and($r->json('mode'))->toBe('live');
    expect(collect($this->get('/api/search?city=Ankara')->json('items'))->pluck('id')->all())->toBe(['ank']);
});

it('applies nearby radii and orders by distance', function () {
    seedBusiness('near', ['latitude' => 41.01, 'longitude' => 29.01]);
    seedBusiness('far', ['latitude' => 41.4, 'longitude' => 29.5]);
    $items = $this->get('/api/search?lat=41&lng=29&radius=100')->json('items');
    expect(collect($items)->pluck('id')->all())->toBe(['near', 'far'])->and($items[0]['distance'])->toBeLessThan($items[1]['distance']);
    expect(collect($this->get('/api/search?lat=41&lng=29&radius=25')->json('items'))->pluck('id')->all())->toBe(['near']);
});

it('imports without overwriting and stays unpublished', function () {
    $file = tempnam(sys_get_temp_dir(), 'cat');
    $line = json_encode(['id' => str_repeat('a', 24), 'candidateId' => str_repeat('b', 24), 'sourceRow' => 1, 'name' => 'Sentetik', 'city' => 'İstanbul', 'district' => 'X', 'address' => 'Y', 'phone' => null, 'website' => null, 'categoryCandidates' => ['a'], 'specialtyCandidates' => [], 'flags' => [], 'rawSource' => ['a' => 1]]);
    file_put_contents($file, $line);
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '1'])->assertSuccessful();
    expect(DB::table('businesses')->value('published'))->toBe(0)->and(DB::table('business_search')->count())->toBe(0);
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '1'])->assertSuccessful()->expectsOutputToContain('atlanan: 1');
    file_put_contents($file, str_replace('Sentetik', 'Degisik', $line));
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '1'])->assertFailed();
    unlink($file);
});
