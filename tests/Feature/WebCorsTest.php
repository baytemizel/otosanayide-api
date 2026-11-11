<?php

use Illuminate\Testing\TestResponse;

$web = 'https://otosanayide.test';
$evil = 'https://evil.test';

function preflight($test, string $uri, string $origin, string $method): TestResponse
{
    return $test->call('OPTIONS', $uri, [], [], [], ['HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method, 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type']);
}

it('answers a web preflight on routes that have no OPTIONS handler', function (string $uri, string $method) use ($web) {
    $r = preflight($this, $uri, $web, $method)->assertStatus(204);
    expect($r->getContent())->toBe('')
        ->and($r->headers->get('Access-Control-Allow-Origin'))->toBe($web)
        ->and($r->headers->get('Access-Control-Allow-Methods'))->toBe($method)
        ->and($r->headers->get('Access-Control-Allow-Headers'))->toBe('Authorization, Content-Type, Accept, Accept-Language, X-Firebase-AppCheck')
        ->and($r->headers->get('Access-Control-Max-Age'))->toBe('600')
        ->and($r->headers->get('Vary'))->toBe('Origin')
        ->and($r->headers->get('Cache-Control'))->toBe('private, no-store');
})->with([
    ['/api/auth/login', 'POST'],
    ['/api/auth/refresh', 'POST'],
    ['/api/me', 'GET'],
    ['/api/me', 'PATCH'],
    ['/api/notifications', 'GET'],
    ['/api/admin/reviews', 'GET'],
    ['/api/businesses/abc-123', 'GET'],
    ['/api/claims', 'POST'],
]);

it('allows credentials only under /api/auth', function () use ($web) {
    expect(preflight($this, '/api/auth/login', $web, 'POST')->headers->get('Access-Control-Allow-Credentials'))->toBe('true')
        ->and(preflight($this, '/api/me', $web, 'GET')->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

it('adds CORS to real responses from the web origin, errors included', function () use ($web) {
    $health = $this->call('GET', '/api/health', [], [], [], ['HTTP_ORIGIN' => $web])->assertOk();
    expect($health->headers->get('Access-Control-Allow-Origin'))->toBe($web)
        ->and($health->headers->get('Access-Control-Expose-Headers'))->toBe('X-Garage-Revision')
        ->and($health->headers->get('Vary'))->toBe('Origin')
        ->and($health->headers->get('Cache-Control'))->toBe('no-store')
        ->and($health->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();

    $me = $this->call('GET', '/api/me', [], [], [], ['HTTP_ORIGIN' => $web])->assertStatus(401);
    expect($me->headers->get('Access-Control-Allow-Origin'))->toBe($web);
    expect($me->headers->get('Cache-Control'))->toBe('private, no-store');

    $refresh = $this->call('POST', '/api/auth/refresh', [], [], [], ['HTTP_ORIGIN' => $web])->assertStatus(401);
    expect($refresh->headers->get('Access-Control-Allow-Origin'))->toBe($web)
        ->and($refresh->headers->get('Access-Control-Allow-Credentials'))->toBe('true');

    $missing = $this->call('GET', '/api/olmayan', [], [], [], ['HTTP_ORIGIN' => $web])->assertStatus(404);
    expect($missing->headers->get('Access-Control-Allow-Origin'))->toBe($web);
});

it('does not repeat Origin in Vary on routes that already set it', function () use ($web) {
    $r = $this->call('GET', '/api/search', [], [], [], ['HTTP_ORIGIN' => $web]);
    expect($r->headers->all('vary'))->toBe(['Origin']);
});

it('gives nothing to a foreign origin', function () use ($evil) {
    $auth = preflight($this, '/api/auth/login', $evil, 'POST');
    expect($auth->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($auth->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();

    $me = $this->call('GET', '/api/me', [], [], [], ['HTTP_ORIGIN' => $evil])->assertStatus(401);
    expect($me->headers->has('Access-Control-Allow-Origin'))->toBeFalse();

    $login = $this->call('POST', '/api/auth/login', [], [], [], ['HTTP_ORIGIN' => $evil, 'CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(403);
    expect($login->headers->has('Access-Control-Allow-Origin'))->toBeFalse();

    preflight($this, '/api/me/favorites', $evil, 'GET')->assertStatus(403);
});

it('does not widen the configured mobile origin to account routes without CORS', function () {
    $r = preflight($this, '/api/auth/login', 'https://m.otosanayide.test', 'POST');
    expect($r->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('does not bless a preflight for a missing route or method', function () use ($web) {
    preflight($this, '/api/olmayan', $web, 'GET')->assertStatus(404);
    // Tarayici Access-Control-Allow-Methods gormeden asil istegi gondermez.
    foreach (['DELETE', 'TRACE'] as $method) {
        expect(preflight($this, '/api/health', $web, $method)->headers->has('Access-Control-Allow-Methods'))->toBeFalse();
    }
});

it('leaves plain OPTIONS requests to the route handlers', function () use ($web) {
    $r = $this->call('OPTIONS', '/api/me/favorites', [], [], [], ['HTTP_ORIGIN' => $web])->assertStatus(204);
    expect($r->headers->get('Access-Control-Allow-Methods'))->toBe('GET, POST, OPTIONS');
});

it('stays out of requests without an Origin', function () {
    $r = $this->get('/api/health');
    expect($r->headers->has('Access-Control-Allow-Origin'))->toBeFalse()->and($r->headers->has('Vary'))->toBeFalse();
});

// Sozlesme Eki v1: PORTAL_WEB_ORIGIN ayni kurallarla CORS alir; /api/admin haric.
it('serves the portal origin like the web origin outside /api/admin', function () {
    $portal = 'https://portal.otosanayide.test';
    config(['otosanayide.portal_web_origin' => $portal]);
    foreach ([['/api/auth/login', 'POST'], ['/api/me', 'GET'], ['/api/businesses/manage', 'PATCH'], ['/api/claims', 'POST'], ['/api/requests', 'PATCH']] as [$uri, $method]) {
        $r = preflight($this, $uri, $portal, $method)->assertStatus(204);
        expect($r->headers->get('Access-Control-Allow-Origin'))->toBe($portal, $uri);
    }
    expect(preflight($this, '/api/auth/refresh', $portal, 'POST')->headers->get('Access-Control-Allow-Credentials'))->toBe('true')
        ->and(preflight($this, '/api/me', $portal, 'GET')->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();

    foreach ([['/api/admin/reviews', 'GET'], ['/api/admin/privacy', 'POST'], ['/api/admin/business-photos', 'PATCH'], ['/api/admin/notifications', 'GET']] as [$uri, $method]) {
        expect(preflight($this, $uri, $portal, $method)->headers->has('Access-Control-Allow-Origin'))->toBeFalse($uri);
        $real = $this->call($method, $uri, [], [], [], ['HTTP_ORIGIN' => $portal, 'CONTENT_TYPE' => 'application/json'], '{}');
        expect($real->headers->has('Access-Control-Allow-Origin'))->toBeFalse($uri);
    }
});

it('ignores an invalid or unset portal origin', function () {
    foreach ([null, '', 'http://portal.otosanayide.test', 'https://portal.otosanayide.test/', 'https://portal.otosanayide.test/x', 'https://otosanayide.test'] as $value) {
        config(['otosanayide.portal_web_origin' => $value]);
        $origin = is_string($value) && $value !== '' ? rtrim(preg_replace('#(https?://[^/]+).*#', '$1', $value), '/') : 'https://portal.otosanayide.test';
        if ($origin === 'https://otosanayide.test') {
            continue; // web ile ayni deger: web kurallari gecerli, portal sayilmaz
        }
        expect(preflight($this, '/api/me', $origin, 'GET')->headers->has('Access-Control-Allow-Origin'))->toBeFalse((string) $value);
    }
    config(['otosanayide.portal_web_origin' => 'http://localhost:3001']);
    expect(preflight($this, '/api/me', 'http://localhost:3001', 'GET')->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:3001');
});
