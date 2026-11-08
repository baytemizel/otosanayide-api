<?php

$anon = [['GET', '/api/me'], ['POST', '/api/me'], ['PATCH', '/api/me'], ['GET', '/api/notifications'], ['PATCH', '/api/notifications'], ['GET', '/api/me/favorites'], ['GET', '/api/me/garage'], ['GET', '/api/me/garage/lifecycle'], ['GET', '/api/me/garage/migrate'], ['GET', '/api/me/privacy'], ['GET', '/api/requests']];

it('requires a token', function (string $m, string $u) {
    $this->call($m, $u, [], [], [], ['HTTP_ACCEPT_LANGUAGE' => ''])->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
})->with($anon);

$origin = [['GET', '/api/me/favorites'], ['GET', '/api/me/garage'], ['GET', '/api/me/garage/lifecycle'], ['GET', '/api/me/garage/migrate'], ['GET', '/api/me/privacy'], ['GET', '/api/me/push'], ['GET', '/api/requests'], ['POST', '/api/requests'], ['PATCH', '/api/requests']];

it('refuses foreign origins', function (string $m, string $u) {
    $this->call($m, $u, [], [], [], ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_ACCEPT_LANGUAGE' => ''])->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
})->with($origin);

it('rejects unknown and duplicate query parameters before auth', function () {
    foreach (['/api/me/favorites?x=1', '/api/me/favorites?cursor=a&cursor=b', '/api/me/favorites?cursor=a&businessId=b', '/api/me/garage?x=1', '/api/me/privacy?x=1', '/api/requests?x=1', '/api/requests?businessId=../bad'] as $u) {
        $this->get($u)->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    }
});

it('checks content type before auth on favorites and requests', function () {
    $this->call('POST', '/api/me/favorites', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}')->assertStatus(415);
    $this->call('POST', '/api/requests', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}')->assertStatus(415);
});

it('rejects push when origin or query is bad, then closed flag, before auth', function () {
    $this->get('/api/me/push?x=1')->assertStatus(403);
    $this->get('/api/me/push')->assertStatus(503)->assertExactJson(['error' => 'Bildirim gönderimi henüz etkin değil.']);
});

it('answers preflights and echoes CORS only for allowed origins', function () {
    $ok = $this->call('OPTIONS', '/api/me/favorites', [], [], [], ['HTTP_ORIGIN' => 'https://m.otosanayide.test']);
    $ok->assertStatus(204);
    expect($ok->headers->get('Access-Control-Allow-Methods'))->toBe('GET, POST, OPTIONS')
        ->and($ok->headers->get('Access-Control-Allow-Origin'))->toBe('https://m.otosanayide.test')
        ->and($ok->headers->get('Cache-Control'))->toBe('private, no-store')
        ->and($ok->headers->get('Vary'))->toBe('Origin');
    $bad = $this->call('OPTIONS', '/api/me/garage', [], [], [], ['HTTP_ORIGIN' => 'https://evil.test']);
    $bad->assertStatus(403);
    expect($bad->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    expect($this->call('OPTIONS', '/api/me/push', [], [], [], ['HTTP_ORIGIN' => 'https://m.otosanayide.test'])->headers->get('Access-Control-Allow-Headers'))->toBe('Authorization, Content-Type, Accept, Accept-Language');
});
