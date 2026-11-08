<?php

use App\Domain\Auth\AuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/*
 * Rota listesinden: gecerli hesap gerektiren kurallar. Kapali yazilan uclar da kapsanir.
 * Eksik sorgu parametresi isteyen uclar icin ornek sorgu verilir.
 */
const MATRIX_QUERY = [
    '/api/reviews/mine' => '?businessId=b1', '/api/review-photos' => '?reviewId=x&version=1', '/api/review-photos/media' => '?id=x',
    '/api/businesses/photos' => '?businessId=b1', '/api/businesses/replies' => '?businessId=b1', '/api/businesses/activation' => '?businessId=b1',
];

// Kaynakta jeton istemeyen uclar; bunlarda hesap kurallari yoktur.
const MATRIX_SKIP = ['/api/health', '/api/search', '/api/businesses/{id}', '/api/reviews', '/api/businesses/photos/public', '/api/businesses/photos/media', '/api/ad-events', '/api/brand-events', '/api/brand-banners', '/api/brand-banners/image', '/api/payments/webhook'];

// Kaynakta dogrulanmamis e-postayi engellemeyen okuma uclari.
const UNVERIFIED_ALLOWED = ['GET /api/me', 'GET /api/notifications', 'PATCH /api/notifications', 'GET /api/claims', 'POST /api/auth/email/resend', 'POST /api/auth/mfa/totp/disable'];

function matrixAccount(string $email, bool $verified = true, array $claims = []): array
{
    Queue::fake();
    $s = new AuthService('127.0.0.1');
    $s->register($email, 'correct-horse-1');
    DB::table('auth_accounts')->where('email', $email)->update($claims + ($verified ? ['email_verified_at' => now('UTC')] : []));
    $id = DB::table('auth_accounts')->where('email', $email)->value('id');

    return [$id, ['HTTP_AUTHORIZATION' => 'Bearer '.$s->login($email, 'correct-horse-1')['session']->accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => '']];
}

function matrixRoutes2(): array
{
    $out = [];
    foreach (Route::getRoutes() as $r) {
        $uri = '/'.$r->uri();
        if (! str_starts_with($uri, '/api/') || str_starts_with($uri, '/api/auth/') || in_array($uri, MATRIX_SKIP, true)) {
            continue;
        }
        foreach ($r->methods() as $m) {
            if (! in_array($m, ['HEAD', 'OPTIONS'], true)) {
                $out[] = [$m, $uri];
            }
        }
    }

    return $out;
}

function matrixCall($t, string $m, string $u, array $auth, string $body = '{}')
{
    return $t->call($m, $u.(MATRIX_QUERY[$u] ?? ''), [], [], [], $auth, $body);
}

it('revoked and expired tokens are refused everywhere', function () {
    [$uid, $auth] = matrixAccount('a@example.com');
    DB::table('auth_accounts')->where('id', $uid)->update(['tokens_valid_after' => now('UTC')->addMinute()]);
    $bad = [];
    foreach (matrixRoutes2() as [$m, $u]) {
        $status = matrixCall($this, $m, $u, $auth)->getStatusCode();
        if ($status !== 401 && ! in_array($u, ['/api/me/push'], true)) {
            $bad[] = "$m $u $status";
        }
    }
    expect($bad)->toBe([]);
});

it('unverified email gets 403 on every write and account route', function () {
    [, $auth] = matrixAccount('u@example.com', false);
    $bad = [];
    foreach (matrixRoutes2() as [$m, $u]) {
        if (in_array("$m $u", UNVERIFIED_ALLOWED, true) || $u === '/api/me/push') {
            continue;
        }
        $status = matrixCall($this, $m, $u, $auth)->getStatusCode();
        if ($status !== 403) {
            $bad[] = "$m $u $status";
        }
    }
    expect($bad)->toBe([]);
});

it('a verified non-admin gets 403 on every admin route', function () {
    [, $auth] = matrixAccount('v@example.com');
    $bad = [];
    foreach (matrixRoutes2() as [$m, $u]) {
        if (! str_starts_with($u, '/api/admin/')) {
            continue;
        }
        $status = matrixCall($this, $m, $u, $auth)->getStatusCode();
        if ($status !== 403) {
            $bad[] = "$m $u $status";
        }
    }
    expect($bad)->toBe([]);
});

it('rate limits every account route at 60 per minute', function () {
    [$uid, $auth] = matrixAccount('r@example.com');
    $bad = [];
    foreach (matrixRoutes2() as [$m, $u]) {
        if (str_starts_with($u, '/api/admin/') || $u === '/api/me/push') {
            continue;
        }
        Illuminate\Support\Facades\RateLimiter::clear('user:'.$uid);
        for ($i = 0; $i < 60; $i++) {
            Illuminate\Support\Facades\RateLimiter::hit('user:'.$uid, 60);
        }
        $status = matrixCall($this, $m, $u, $auth)->getStatusCode();
        if ($status !== 429) {
            $bad[] = "$m $u $status";
        }
    }
    expect($bad)->toBe([]);
});

it('rejects oversize bodies on write routes that read JSON', function () {
    [, $auth] = matrixAccount('b@example.com');
    $big = '{"pad":"'.str_repeat('a', 600000).'"}';
    $bad = [];
    foreach (matrixRoutes2() as [$m, $u]) {
        // Kapali uclar govdeyi okumaz; medya uclari ayri ikili sinir kullanir (F5 testleri).
        if (! in_array($m, ['POST', 'PATCH', 'PUT'], true) || in_array($u, ['/api/review-photos', '/api/businesses/photos', '/api/claims/evidence', '/api/me/push', '/api/me/garage', '/api/me/garage/lifecycle', '/api/me/garage/migrate', '/api/me/privacy', '/api/admin/privacy'], true) || collect(App\Http\Controllers\Api\Closed\ClosedFeatureController::SPECS)->has($m.' '.substr($u, 5))) {
            continue;
        }
        $status = matrixCall($this, $m, $u, $auth, $big)->getStatusCode();
        if ($status !== 413) {
            $bad[] = "$m $u $status";
        }
    }
    expect($bad)->toBe([]);
});
