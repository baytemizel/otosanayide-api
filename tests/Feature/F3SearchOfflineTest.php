<?php

it('returns 400 with the search error and public no-store headers', function () {
    $r = $this->get('/api/search?limit=99999')->assertStatus(400)->assertExactJson(['error' => 'Arama filtrelerini kontrol et.']);
    expect($r->headers->get('Cache-Control'))->toBe('no-store')->and($r->headers->get('Vary'))->toBe('Origin');
});

it('rejects foreign origins with the source Forbidden body', function () {
    $this->withHeader('Origin', 'https://evil.test')->get('/api/search')->assertStatus(403)->assertExactJson(['error' => 'Forbidden']);
});

it('answers preflight like the source', function () {
    $r = $this->call('OPTIONS', '/api/search', [], [], [], ['HTTP_ORIGIN' => 'https://m.otosanayide.test']);
    $r->assertStatus(204);
    expect($r->headers->get('Access-Control-Allow-Methods'))->toBe('GET, OPTIONS')
        ->and($r->headers->get('Access-Control-Allow-Headers'))->toBe('Accept, Accept-Language')
        ->and($r->headers->get('Access-Control-Max-Age'))->toBe('600')
        ->and($r->headers->get('Access-Control-Allow-Origin'))->toBe('https://m.otosanayide.test');
    $this->call('OPTIONS', '/api/search', [], [], [], ['HTTP_ORIGIN' => 'https://evil.test'])->assertStatus(403);
});

it('reports 503 when the search database is unreachable', function () {
    $this->get('/api/search?city=Ankara')->assertStatus(503)->assertExactJson(['error' => 'Arama altyapısı henüz yayına açılmadı.']);
});

it('keeps catalogue:import dry by default', function () {
    $file = tempnam(sys_get_temp_dir(), 'cat');
    file_put_contents($file, json_encode(['id' => str_repeat('a', 24), 'candidateId' => str_repeat('b', 24), 'sourceRow' => 1, 'name' => 'S', 'city' => 'I', 'district' => 'X', 'address' => 'Y', 'phone' => null, 'website' => null, 'categoryCandidates' => [], 'specialtyCandidates' => [], 'flags' => [], 'rawSource' => ['a' => 1]]));
    $this->artisan('catalogue:import', ['path' => $file])->expectsOutputToContain('Dogrulanan satir: 1')->expectsOutputToContain('veritabanina baglanilmadi')->assertSuccessful();
    $this->artisan('catalogue:import', ['path' => $file, '--apply' => true, '--confirm-count' => '2'])->assertFailed();
    unlink($file);
});
