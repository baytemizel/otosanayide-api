<?php

use App\Domain\Auth\AuthService;
use App\Domain\Catalogue\SearchProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function userToken(string $email = 'u@example.com', bool $verified = true): array
{
    Queue::fake();
    $service = new AuthService('127.0.0.1');
    $service->register($email, 'correct-horse-1');
    $id = DB::table('auth_accounts')->where('email', $email)->value('id');
    if ($verified) {
        DB::table('auth_accounts')->where('id', $id)->update(['email_verified_at' => now('UTC')]);
    }
    $login = $service->login($email, 'correct-horse-1');

    return [$id, ['HTTP_AUTHORIZATION' => 'Bearer '.$login['session']->accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => '']];
}

function api($test, string $m, string $u, array $auth, mixed $body = null)
{
    return $test->call($m, $u, [], [], [], $auth, $body === null ? null : json_encode($body));
}

function shop(string $id, array $over = []): void
{
    DB::table('businesses')->insert($over + ['id' => $id, 'name' => 'Usta Motor', 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sanayi Sitesi No 12 Ümraniye', 'phone' => '', 'website' => '', 'description' => '', 'latitude' => 41.02, 'longitude' => 29.1, 'coordinate_status' => 'verified', 'published' => true, 'ownership' => 'verified', 'owner_ready' => true, 'feature_quotes' => true]);
    DB::table('business_values')->insert(['business_id' => $id, 'kind' => 'category', 'position' => 0, 'value' => 'mechanical']);
    SearchProjection::sync($id);
}

it('gates unverified email with 403', function () {
    [, $auth] = userToken(verified: false);
    api($this, 'GET', '/api/me/favorites', $auth)->assertStatus(403)->assertExactJson(['error' => 'Önce e-posta adresini doğrula.']);
    api($this, 'POST', '/api/me', $auth, ['resource' => 'favorites', 'value' => []])->assertStatus(403);
});

it('returns closed flag responses after auth', function () {
    [, $auth] = userToken();
    api($this, 'GET', '/api/me/garage', $auth)->assertStatus(503)->assertExactJson(['error' => 'Garaj yedekleme henüz etkin değil.']);
    api($this, 'GET', '/api/me/garage/migrate', $auth)->assertStatus(503);
    api($this, 'GET', '/api/me/privacy', $auth)->assertStatus(503)->assertExactJson(['error' => 'Veri talepleri henüz kullanıma açılmadı.']);
});

it('enforces 60 per minute per user', function () {
    [, $auth] = userToken();
    foreach (range(1, 60) as $_) {
        api($this, 'GET', '/api/me/favorites', $auth)->assertOk();
    }
    api($this, 'GET', '/api/me/favorites', $auth)->assertStatus(429);
});

it('stores favorites per account, never by client id (IDOR)', function () {
    shop('b1');
    [$a, $authA] = userToken('a@example.com');
    [, $authB] = userToken('b@example.com');
    api($this, 'POST', '/api/me/favorites', $authA, ['businessId' => 'b1', 'adding' => true])->assertOk()->assertExactJson(['saved' => true]);
    api($this, 'GET', '/api/me/favorites?businessId=b1', $authA)->assertExactJson(['saved' => true]);
    contract('live-favorites-page', 'favorites-page', api($this, 'GET', '/api/me/favorites', $authA)->assertOk()->json());
    api($this, 'GET', '/api/me/favorites?businessId=b1', $authB)->assertExactJson(['saved' => false]);
    api($this, 'POST', '/api/me/favorites', $authB, ['businessId' => 'b1', 'adding' => true, 'userId' => $a])->assertStatus(400);
});

it('hides vehicles from other accounts and caps at 50', function () {
    [$a, $authA] = userToken('a@example.com');
    [, $authB] = userToken('b@example.com');
    $v = ['resource' => 'vehicles', 'value' => ['brand' => 'BMW', 'model' => 'X', 'year' => 2020, 'fuel' => 'Dizel', 'mileage' => 5]];
    $id = api($this, 'POST', '/api/me', $authA, $v)->assertStatus(201)->json('id');
    contract('live-account', 'account', api($this, 'GET', '/api/me', $authA)->assertOk()->json());
    expect(collect(api($this, 'GET', '/api/me', $authB)->json('vehicles')))->toBeEmpty();
    api($this, 'PATCH', '/api/me', $authB, ['resource' => 'vehicles', 'id' => $id, 'value' => $v['value']])->assertStatus(409);
    foreach (range(2, 50) as $i) {
        DB::table('user_vehicles')->insert(['user_id' => $a, 'id' => 'v'.$i, 'brand' => 'B', 'model' => 'M', 'year' => 2020, 'fuel' => 'LPG', 'mileage' => 1]);
    }
    api($this, 'POST', '/api/me', $authA, $v)->assertStatus(409);
});

it('blocks writes while an erasure fence is active', function () {
    [$a, $auth] = userToken();
    DB::table('account_fences')->insert(['owner_uid' => $a, 'job_id' => (string) Str::uuid(), 'epoch' => (string) Str::uuid(), 'state' => 'deleting', 'created_at_ms' => 1]);
    shop('b1');
    api($this, 'POST', '/api/me/favorites', $auth, ['businessId' => 'b1', 'adding' => true])->assertStatus(409);
});

it('runs the request lifecycle, forbids strangers and never double books a slot', function () {
    shop('b1', ['feature_appointments' => true, 'owner_uid' => 'x']);
    [$customer, $c] = userToken('c@example.com');
    [$owner, $o] = userToken('o@example.com');
    [, $stranger] = userToken('s@example.com');
    DB::table('business_memberships')->insert(['user_id' => $owner, 'business_id' => 'b1', 'role' => 'owner', 'active' => true]);
    $body = ['businessId' => 'b1', 'kind' => 'quotes', 'service' => 'Fren', 'vehicle' => 'BMW', 'message' => 'Fren balatası değişimi', 'requestKey' => (string) Str::uuid()];
    $id = api($this, 'POST', '/api/requests', $c, $body)->assertStatus(201)->json('id');
    api($this, 'POST', '/api/requests', $c, $body)->assertStatus(201)->assertJsonPath('status', 'requested');
    api($this, 'POST', '/api/requests', $c, array_merge($body, ['message' => 'Farklı içerikli talep metni']))->assertStatus(409);
    api($this, 'POST', '/api/requests', $o, array_merge($body, ['requestKey' => (string) Str::uuid()]))->assertStatus(403);
    api($this, 'GET', '/api/requests?businessId=b1', $stranger)->assertStatus(403);
    api($this, 'PATCH', '/api/requests', $stranger, ['id' => $id, 'version' => 1, 'action' => 'cancel', 'text' => 'Yetkisiz kullanıcı denemesi'])->assertStatus(403);
    api($this, 'PATCH', '/api/requests', $o, ['businessId' => 'b1', 'value' => ['id' => $id, 'version' => 1, 'action' => 'offer', 'text' => 'İşçilik dahil teklifimiz', 'price' => 500]])->assertOk();
    api($this, 'PATCH', '/api/requests', $o, ['businessId' => 'b1', 'value' => ['id' => $id, 'version' => 1, 'action' => 'decline', 'text' => 'Eski sürüm ile deneme']])->assertStatus(409);
    api($this, 'PATCH', '/api/requests', $c, ['id' => $id, 'version' => 2, 'action' => 'accept'])->assertOk();
    contract('live-requests', 'requests', api($this, 'GET', '/api/requests', $c)->assertOk()->json());
    contract('live-owner-requests', 'owner-requests', api($this, 'GET', '/api/requests?businessId=b1', $o)->assertOk()->json(), ['businessId' => 'b1']);
    expect(DB::table('notification_jobs')->count())->toBe(3)->and(DB::table('request_history')->count())->toBe(2);
    expect($customer)->toBeString();
});

it('binds notification read receipts to the audience', function () {
    [$a, $authA] = userToken('a@example.com');
    [, $authB] = userToken('b@example.com');
    DB::table('notifications')->insert(['id' => 'n1', 'audience_key' => 'user:'.$a, 'request_id' => 'r', 'request_status' => 'requested', 'created_at_ms' => 1]);
    api($this, 'PATCH', '/api/notifications', $authB, ['id' => 'n1', 'read' => true])->assertStatus(403);
    api($this, 'PATCH', '/api/notifications', $authA, ['id' => 'n1', 'read' => true])->assertOk();
    expect(api($this, 'GET', '/api/notifications', $authA)->json('items.0.read'))->toBeTrue();
});

it('refuses binding one push token to two devices', function () {
    config(['otosanayide.flags.push_delivery' => true]);
    putenv('PUSH_PUBLIC_APP_IDS=1:1:web:ab');
    [, $a] = userToken('a@example.com');
    [, $b] = userToken('b@example.com');
    $cmd = ['action' => 'register', 'deviceId' => (string) Str::uuid(), 'token' => str_repeat('t', 30), 'provider' => 'fcm', 'platform' => 'web', 'locale' => 'tr', 'appId' => '1:1:web:ab'];
    api($this, 'POST', '/api/me/push', $a, $cmd)->assertOk();
    api($this, 'POST', '/api/me/push', $b, array_merge($cmd, ['deviceId' => (string) Str::uuid()]))->assertStatus(409);
});
