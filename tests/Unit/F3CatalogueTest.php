<?php

use App\Console\Commands\CatalogueImport;
use App\Domain\Catalogue\BusinessReader;
use App\Domain\Catalogue\Contact;
use App\Domain\Catalogue\SearchFilter;
use App\Domain\Catalogue\SearchService;
use App\Domain\Catalogue\Taxonomy;
use App\Support\Query;

function sf(string $q): SearchFilter
{
    return SearchFilter::parse(Query::parse($q));
}

// packages/core/search-request.test.ts ve coverage.test.ts ornekleri.
it('rejects invalid search requests', function (string $q) {
    expect(fn () => sf($q))->toThrow(InvalidArgumentException::class);
})->with([
    'city=İstanbul&city=Ankara', 'limit=99999', 'lat=&lng=0', 'lat=1', 'lng=29', 'lat=NaN&lng=29', 'lat=91&lng=29',
    'radius=20', 'card=yes', 'category=anything', 'district=Kadıköy', 'city=Mars', 'service=Fren',
    'city=Bitlis&lat=38.5&lng=43.3&radius=201', 'city=Bitlis&lat=38.5&lng=43.3&radius=0',
]);

it('parses precise specialist filtering and radius bounds', function () {
    $v = sf('city=İstanbul&category=turbo&service=Turbo+tamiri&lat=41&lng=29&radius=25');
    expect($v->service)->toBe('Turbo tamiri')->and($v->radius)->toBe(25.0)->and($v->center)->toBe(['latitude' => 41.0, 'longitude' => 29.0]);
    expect(sf('city=Bitlis&lat=38.5&lng=43.3&radius=200')->radius)->toBe(200.0);
    expect(sf('card=true')->card)->toBeTrue()->and(sf('card=false')->card)->toBeFalse();
});

it('bounds text by JS length', function () {
    expect(fn () => sf('q='.str_repeat('a', 151)))->toThrow(InvalidArgumentException::class);
    expect(sf('q='.str_repeat('a', 150))->q)->toHaveLength(150);
});

it('normalizes Turkish text like the source', function () {
    expect(Taxonomy::normalize('İSTANBUL Şanzıman'))->toBe('istanbul sanziman')
        ->and(Taxonomy::normalize('Çağrı-Göçük  (Özel)'))->toBe('cagri gocuk ozel')
        ->and(Taxonomy::normalize('IĞDIR'))->toBe('igdir');
});

it('expands aliases and english labels in query terms', function () {
    expect(Taxonomy::queryTerms('elektirik BMW'))->toBe(['elektrik', 'bmw'])
        ->and(Taxonomy::queryTerms('oto servis usta'))->toBe([])
        ->and(Taxonomy::queryTerms('oil change'))->toBe(['periyodik', 'bakim'])
        ->and(Taxonomy::queryTerms('tires', false))->toBe(['tires']);
});

it('computes distance like distanceKm', function () {
    expect(round(Taxonomy::distanceKm(['latitude' => 41.0, 'longitude' => 29.0], ['latitude' => 41.0, 'longitude' => 29.0]), 6))->toBe(0.0);
    expect(Taxonomy::distanceKm(['latitude' => 41.0, 'longitude' => 29.0], ['latitude' => 39.92, 'longitude' => 32.85]))->toBeGreaterThan(340.0)->toBeLessThan(360.0);
});

it('normalizes phones like contactPhone', function () {
    expect(Contact::phone('0532 123 45 67'))->toBe(['e164' => '+905321234567', 'mobile' => true])
        ->and(Contact::phone('444 1 234')['national'] ?? null)->toBeTrue()
        ->and(Contact::phone('123'))->toBeNull()
        ->and(Contact::phone('+4915112345678'))->toBe(['e164' => '+4915112345678', 'mobile' => false]);
});

function rec(array $over = []): array
{
    return $over + [
        'name' => 'Usta Motor', 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sanayi Sitesi No 12 Ümraniye', 'phone' => '', 'contactEmail' => null,
        'website' => '', 'hours' => '', 'description' => '', 'categories' => ['mechanical'], 'services' => ['Fren'], 'brands' => ['BMW'],
        'published' => true, 'sample' => false, 'ownership' => 'unclaimed', 'ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false],
        'coordinateStatus' => 'verified', 'latitude' => 41.02, 'longitude' => 29.1, 'cardPayment' => null, 'wc' => null, 'businessCoverPhotoId' => null, 'publicBusinessPhotoIds' => [],
    ];
}

it('exposes only the minimized public DTO', function () {
    $v = BusinessReader::publicBusiness('b1', rec(['ownership' => 'verified', 'ownerReady' => true, 'features' => ['quotes' => true, 'appointments' => false]]));
    expect($v['features'])->toBe(['quotes' => true, 'appointments' => false])->and($v)->not->toHaveKey('sample')->and($v)->not->toHaveKey('description');
    expect(BusinessReader::publicBusiness('b1', rec(['published' => false])))->toBeNull()
        ->and(BusinessReader::publicBusiness('b1', rec(['sample' => true])))->toBeNull()
        ->and(BusinessReader::publicBusiness('b1', rec(['address' => 'kisa'])))->toBeNull()
        ->and(BusinessReader::publicBusiness('bad id', rec()))->toBeNull();
});

it('hides coordinates outside the Turkey box and never trusts pins as verified', function () {
    $v = BusinessReader::publicBusiness('b1', rec(['latitude' => 10.0]));
    expect($v['coordinateStatus'])->toBe('unverified')->and($v)->not->toHaveKey('latitude');
});

it('indexes only published records with approved coordinates', function () {
    expect(BusinessReader::indexDocument('b1', rec()))->not->toBeNull();
    expect(BusinessReader::indexDocument('b1', rec(['coordinateStatus' => 'unverified'])))->toBeNull();
});

it('applies the final filter exactly', function () {
    $s = new SearchService;
    $b = BusinessReader::publicBusiness('b1', rec());
    expect($s->matches($b, sf('city=Ankara')))->toBeNull()
        ->and($s->matches($b, sf('city=İstanbul&district=umraniye')))->not->toBeNull()
        ->and($s->matches($b, sf('card=true')))->toBeNull()
        ->and($s->matches($b, sf('category=body')))->toBeNull()
        ->and($s->matches($b, sf('lat=41&lng=29&radius=1')))->toBeNull();
    expect($s->matches($b, sf('lat=41&lng=29&radius=25')))->toHaveKey('distance');
});

$row = fn (string $id, array $o = []) => json_encode($o + ['id' => $id, 'candidateId' => str_repeat('b', 24), 'sourceRow' => 1, 'name' => 'Sentetik', 'city' => 'İstanbul', 'district' => 'X', 'address' => 'Y', 'phone' => null, 'website' => null, 'categoryCandidates' => [], 'specialtyCandidates' => [], 'flags' => [], 'rawSource' => ['k' => 'v']]);

it('prepares import rows without touching a database and keeps id format', function () use ($row) {
    $entries = CatalogueImport::prepare("\u{FEFF}".$row(str_repeat('a', 24))."\n\n".$row(str_repeat('c', 24))."\r\n");
    expect($entries)->toHaveCount(2)->and($entries[0]['businessId'])->toBe('import-'.str_repeat('a', 24));
});

it('refuses duplicates and oversized rows', function () use ($row) {
    expect(fn () => CatalogueImport::prepare($row(str_repeat('a', 24))."\n".$row(str_repeat('a', 24))))->toThrow(RuntimeException::class, 'Duplicate');
    expect(fn () => CatalogueImport::prepare($row(str_repeat('a', 24), ['pad' => str_repeat('x', 250001)])))->toThrow(RuntimeException::class, 'Oversized');
});

it('hashes key order independently', function () {
    $a = CatalogueImport::prepare(json_encode(['id' => str_repeat('a', 24), 'candidateId' => str_repeat('b', 24), 'sourceRow' => 1, 'name' => 'S', 'city' => 'I', 'district' => 'X', 'address' => 'Y', 'phone' => null, 'website' => null, 'categoryCandidates' => [], 'specialtyCandidates' => [], 'flags' => [], 'rawSource' => ['a' => 1, 'b' => 2]]));
    $b = CatalogueImport::prepare(json_encode(['rawSource' => ['b' => 2, 'a' => 1], 'flags' => [], 'specialtyCandidates' => [], 'categoryCandidates' => [], 'website' => null, 'phone' => null, 'address' => 'Y', 'district' => 'X', 'city' => 'I', 'name' => 'S', 'sourceRow' => 1, 'candidateId' => str_repeat('b', 24), 'id' => str_repeat('a', 24)]));
    expect($a[0]['sourceHash'])->toBe($b[0]['sourceHash']);
});
