<?php

use App\Domain\Auth\ActorResolver;
use Illuminate\Support\Facades\Route;

/*
 * Rota listesinden uretilir: yeni uc eklenince kendiliginden kapsanir.
 * Veritabani gerektirmeyen iki kural: jetonsuz istek ve yabanci origin.
 */
function matrixRoutes(): array
{
    $out = [];
    foreach (Route::getRoutes() as $r) {
        foreach ($r->methods() as $m) {
            if (! in_array($m, ['HEAD', 'OPTIONS'], true) && str_starts_with($r->uri(), 'api/')) {
                $out[] = [$m, '/'.$r->uri(), $r];
            }
        }
    }

    return $out;
}

// Kaynakta jeton istemeyen uclar.
const PUBLIC_ROUTES = [
    'GET /api/health', 'GET /api/search', 'GET /api/businesses/{id}', 'GET /api/reviews', 'GET /api/businesses/photos/public', 'GET /api/businesses/photos/media',
    'POST /api/ad-events', 'POST /api/brand-events', 'GET /api/brand-banners', 'GET /api/brand-banners/image', 'POST /api/payments/webhook',
    'GET /api/businesses/{id}/availability',
    'POST /api/auth/register', 'POST /api/auth/login', 'POST /api/auth/mfa/verify', 'POST /api/auth/refresh', 'POST /api/auth/logout', 'POST /api/auth/email/verify', 'POST /api/auth/password/forgot', 'POST /api/auth/password/reset',
];

// Kaynakta sorgu dogrulamasi veya bayrak jetondan once gelen uclar (handle/requestHttpInput sarmalayicilari).
const PRE_AUTH = [
    'GET /api/me/push' => 503, 'POST /api/me/push' => 503,
    // Sozlesme Eki v1: oturum veren uc, Origin'siz istekte istemci kimligini jetondan once ister.
    'POST /api/auth/password/change' => 400,
    // 2. tur: sorgu dogrulamasi jetondan once (kaynak sirasi).
    'DELETE /api/me/lists' => 400, 'DELETE /api/coverage/waitlist' => 400, 'GET /api/requests/messages' => 400, 'GET /api/businesses/insights' => 400,
    'GET /api/businesses/team' => 400, 'GET /api/businesses/capacity' => 400, 'DELETE /api/businesses/capacity/closures' => 400,
    'GET /api/reviews/mine' => 400, 'GET /api/review-photos' => 400, 'POST /api/review-photos' => 400, 'GET /api/review-photos/media' => 400,
    'GET /api/businesses/photos' => 400, 'POST /api/businesses/photos' => 400, 'GET /api/businesses/replies' => 400, 'GET /api/businesses/activation' => 400,
    // F7 (API-B): campaignHttpInput / adFinanceHttp jetondan once.
    'GET /api/campaigns' => 400, 'GET /api/campaigns/finance' => 400, 'GET /api/campaigns/owner-metrics' => 400,
];

it('every protected route refuses a request with no token', function () {
    $checked = 0;
    $mismatches = [];
    foreach (matrixRoutes() as [$method, $uri]) {
        $key = $method.' '.$uri;
        if (in_array($key, PUBLIC_ROUTES, true)) {
            continue;
        }
        $expected = PRE_AUTH[$key] ?? 401;
        $response = $this->call($method, str_replace('{id}', 'x', $uri), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}');
        if ($response->getStatusCode() !== $expected) {
            $mismatches[] = $key.' => '.$response->getStatusCode();
        }
        expect(json_decode($response->getContent(), true))->toHaveKey('error');
        $checked++;
    }
    expect($mismatches)->toBe([]);
    expect($checked)->toBeGreaterThan(60);
});

it('every origin-guarded route refuses a foreign origin', function () {
    $checked = 0;
    foreach (matrixRoutes() as [$method, $uri, $route]) {
        $guarded = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'origin:'));
        if (! $guarded) {
            continue;
        }
        $response = $this->call($method, str_replace('{id}', 'x', $uri), [], [], [], ['HTTP_ORIGIN' => 'https://evil.test', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}');
        expect($response->getStatusCode())->toBe(403, $method.' '.$uri);
        $checked++;
    }
    expect($checked)->toBeGreaterThan(40);
});

it('never answers a protected route from a malformed bearer', function () {
    foreach (['', 'Basic abc', 'Bearer'] as $header) {
        $r = $this->call('GET', '/api/me', [], [], [], ['HTTP_AUTHORIZATION' => $header, 'HTTP_ACCEPT_LANGUAGE' => '']);
        expect($r->getStatusCode())->toBe(401);
    }
    expect(class_exists(ActorResolver::class))->toBeTrue();
});

// Sozlesme Eki v1: native istemci Origin gondermez; kimlik basliklari origin kapisini degistirmez.
it('lets a native client past every origin gate to the token check', function () {
    $mismatches = [];
    foreach (matrixRoutes() as [$method, $uri]) {
        $key = $method.' '.$uri;
        if (in_array($key, PUBLIC_ROUTES, true)) {
            continue;
        }
        $expected = $key === 'POST /api/auth/password/change' ? 401 : (PRE_AUTH[$key] ?? 401);
        $r = $this->call($method, str_replace('{id}', 'x', $uri), [], [], [], ['HTTP_X_CLIENT_PLATFORM' => 'android', 'HTTP_X_CLIENT_VERSION' => '1.0.0', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}');
        if ($r->getStatusCode() !== $expected) {
            $mismatches[] = $key.' => '.$r->getStatusCode();
        }
    }
    expect($mismatches)->toBe([]);
});

it('treats the portal like the web origin everywhere except /api/admin', function () {
    config(['otosanayide.portal_web_origin' => 'https://portal.otosanayide.test']);
    $checked = 0;
    $mismatches = [];
    foreach (matrixRoutes() as [$method, $uri, $route]) {
        $guarded = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'origin:'));
        if (! $guarded) {
            continue;
        }
        $admin = str_starts_with($uri, '/api/admin');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''];
        $portal = $this->call($method, str_replace('{id}', 'x', $uri), [], [], [], $server + ['HTTP_ORIGIN' => 'https://portal.otosanayide.test'], '{}');
        $web = $this->call($method, str_replace('{id}', 'x', $uri), [], [], [], $server + ['HTTP_ORIGIN' => 'https://otosanayide.test'], '{}');
        $expected = $admin ? 403 : $web->getStatusCode();
        if ($portal->getStatusCode() !== $expected) {
            $mismatches[] = $method.' '.$uri.' => '.$portal->getStatusCode().' (beklenen '.$expected.')';
        }
        if ($admin) {
            expect($portal->headers->has('Access-Control-Allow-Origin'))->toBeFalse($method.' '.$uri);
        }
        $checked++;
    }
    expect($mismatches)->toBe([])->and($checked)->toBeGreaterThan(40);
});
