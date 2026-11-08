<?php

function raw($t, string $m, string $u, array $s = [], ?string $b = null)
{
    return $t->call($m, $u, [], [], [], $s + ['HTTP_ACCEPT_LANGUAGE' => ''], $b);
}

it('returns the source closed responses with no database', function () {
    raw($this, 'POST', '/api/ad-events')->assertStatus(204);
    raw($this, 'POST', '/api/brand-events')->assertStatus(204);
    raw($this, 'POST', '/api/payments/webhook')->assertStatus(503)->assertExactJson(['received' => false]);
    raw($this, 'GET', '/api/brand-banners/image')->assertStatus(404);
    $r = raw($this, 'GET', '/api/brand-banners?placement=home')->assertOk()->assertExactJson(['items' => []]);
    expect($r->headers->get('Vary'))->toBe('X-Firebase-AppCheck');
    raw($this, 'GET', '/api/brand-banners?a=1&a=2')->assertStatus(400);
});

it('keeps authentication ahead of closed answers', function (string $m, string $u) {
    raw($this, $m, $u)->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
})->with([['GET', '/api/admin/brand-orders'], ['POST', '/api/admin/brand-orders'], ['PATCH', '/api/admin/ad-tariffs'], ['GET', '/api/campaigns'], ['POST', '/api/campaigns/reservation'], ['GET', '/api/campaigns/finance'], ['DELETE', '/api/admin/brand-reservations']]);

it('checkout refuses bad origin and query before auth', function () {
    raw($this, 'POST', '/api/payments/checkout', ['HTTP_ORIGIN' => 'https://evil.test'])->assertStatus(403);
    raw($this, 'POST', '/api/payments/checkout?x=1')->assertStatus(403);
    raw($this, 'POST', '/api/payments/checkout')->assertStatus(401);
});

it('answers closed feature preflights', function () {
    $ok = raw($this, 'OPTIONS', '/api/campaigns/owner-metrics', ['HTTP_ORIGIN' => 'https://otosanayide.test']);
    $ok->assertStatus(204);
    expect($ok->headers->get('Access-Control-Allow-Methods'))->toBe('GET, OPTIONS');
    expect(raw($this, 'OPTIONS', '/api/campaigns', ['HTTP_ORIGIN' => 'https://otosanayide.test'])->headers->get('Access-Control-Allow-Methods'))->toBe('GET, PATCH, OPTIONS');
    raw($this, 'OPTIONS', '/api/payments/checkout', ['HTTP_ORIGIN' => 'https://evil.test'])->assertStatus(403);
});
