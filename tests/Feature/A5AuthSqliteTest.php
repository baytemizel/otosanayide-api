<?php

use App\Domain\Auth\Totp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/*
 * Gercek AuthService ve NotificationsController, surec ici SQLite bellek veritabaninda (uretim MySQL'ine
 * baglanilmaz, DB_PORT=1 korunur). Tablolar yalniz kullanilan kolonlarla elle kurulur.
 * MySQL kabulu DEGILDIR: kilit/izolasyon ve gercek sema kisitlari burada denetlenmez.
 */

beforeEach(function () {
    config(['database.connections.a5' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false], 'database.default' => 'a5', 'otosanayide.portal_web_origin' => 'https://portal.otosanayide.test']);
    DB::purge('a5');
    $s = Schema::connection('a5');
    $s->create('auth_accounts', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('email')->unique(), $t->string('email_verified_at')->nullable(), $t->string('password_hash'), $t->string('password_changed_at'), $t->string('tokens_valid_after'), $t->boolean('is_admin')->default(false), $t->boolean('is_account_erasure_operator')->default(false), $t->boolean('is_brand_finance')->default(false), $t->string('status')->default('active')]);
    $s->create('auth_sessions', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('account_id'), $t->string('family_id'), $t->string('refresh_token_hash')->unique(), $t->string('second_factor')->nullable(), $t->string('auth_time'), $t->string('expires_at'), $t->string('revoked_at')->nullable(), $t->string('replaced_by')->nullable(), $t->string('last_used_at')->nullable()]);
    $s->create('personal_access_tokens', fn (Blueprint $t) => [$t->id(), $t->string('tokenable_type'), $t->string('tokenable_id'), $t->string('session_id'), $t->text('name'), $t->string('token')->unique(), $t->text('abilities')->nullable(), $t->string('last_used_at')->nullable(), $t->string('expires_at')->nullable(), $t->string('created_at')->nullable(), $t->string('updated_at')->nullable()]);
    $s->create('auth_mfa_factors', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('account_id'), $t->string('type'), $t->text('secret_ciphertext')->nullable(), $t->integer('last_used_step')->nullable(), $t->string('verified_at')->nullable(), $t->string('disabled_at')->nullable()]);
    $s->create('auth_email_tokens', fn (Blueprint $t) => [$t->string('token_hash')->primary(), $t->string('account_id'), $t->string('purpose'), $t->string('expires_at'), $t->string('consumed_at')->nullable()]);
    $s->create('auth_events', fn (Blueprint $t) => [$t->id(), $t->string('account_id')->nullable(), $t->string('event'), $t->string('ip_hash')->nullable()]);
    $s->create('account_fences', fn (Blueprint $t) => [$t->string('owner_uid')->primary(), $t->string('state')]);
    $s->create('notifications', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('audience_key'), $t->string('business_id')->nullable(), $t->string('request_id')->nullable(), $t->string('request_status')->nullable(), $t->string('kind')->nullable(), $t->string('vehicle_label')->nullable(), $t->string('title')->nullable(), $t->string('phase')->nullable(), $t->string('due_date')->nullable(), $t->integer('due_km')->nullable(), $t->bigInteger('created_at_ms')]);
    $s->create('notification_reads', fn (Blueprint $t) => [$t->string('notification_id'), $t->string('user_id'), $t->boolean('read'), $t->bigInteger('updated_at_ms'), $t->primary(['notification_id', 'user_id'])]);
    DB::table('auth_accounts')->insert(['id' => 'u5', 'email' => 'u5@example.com', 'email_verified_at' => '2026-01-01 00:00:00.000', 'password_hash' => Hash::make('correct-horse-1'), 'password_changed_at' => '2026-01-01 00:00:00.000', 'tokens_valid_after' => '2026-01-01 00:00:00.000']);
});

function a5(string $method, string $uri, array $server = [], ?string $body = null): TestResponse
{
    return test()->call($method, $uri, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], $body);
}

function a5Native(): array
{
    return ['HTTP_X_CLIENT_PLATFORM' => 'ios', 'HTTP_X_CLIENT_VERSION' => '1.0.0'];
}

function a5Login(): array
{
    return a5('POST', '/api/auth/login', a5Native(), '{"email":"u5@example.com","password":"correct-horse-1"}')->assertOk()->json();
}

it('rotates a real native refresh token and revokes the family on reuse', function () {
    $s1 = a5Login();
    $s2 = a5('POST', '/api/auth/refresh', a5Native(), json_encode(['refreshToken' => $s1['refreshToken']]))->assertOk()->json();
    expect($s2['refreshToken'])->not->toBe($s1['refreshToken']);
    a5('GET', '/api/auth/mfa', ['HTTP_AUTHORIZATION' => 'Bearer '.$s2['accessToken']])->assertOk();

    a5('POST', '/api/auth/refresh', a5Native(), json_encode(['refreshToken' => $s1['refreshToken']]))->assertStatus(401);
    a5('POST', '/api/auth/refresh', a5Native(), json_encode(['refreshToken' => $s2['refreshToken']]))->assertStatus(401);
    a5('GET', '/api/auth/mfa', ['HTTP_AUTHORIZATION' => 'Bearer '.$s2['accessToken']])->assertStatus(401);
    expect(DB::table('auth_sessions')->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('auth_events')->where('event', 'refresh_reuse')->count())->toBe(2); // eski jeton + iptal edilen yeni jeton
});

it('reports TOTP status without exposing the secret', function () {
    $s = a5Login();
    a5('GET', '/api/auth/mfa', ['HTTP_AUTHORIZATION' => 'Bearer '.$s['accessToken']])->assertOk()->assertExactJson(['totp' => ['enabled' => false, 'enabledAtMs' => null]]);
    DB::table('auth_mfa_factors')->insert(['id' => 'f1', 'account_id' => 'u5', 'type' => 'totp', 'secret_ciphertext' => Crypt::encryptString('JBSWY3DPEHPK3PXP'), 'verified_at' => '2026-11-10 12:00:00.000']);
    a5('GET', '/api/auth/mfa', ['HTTP_AUTHORIZATION' => 'Bearer '.$s['accessToken']])->assertOk()->assertExactJson(['totp' => ['enabled' => true, 'enabledAtMs' => 1794312000000]]);
    a5('GET', '/api/auth/mfa')->assertStatus(401);
});

it('changes the password, revokes every other session and returns a fresh one', function () {
    $old = a5Login();
    $other = a5Login();
    a5('POST', '/api/auth/password/change', a5Native() + ['HTTP_AUTHORIZATION' => 'Bearer '.$old['accessToken']], '{"currentPassword":"wrong-password","newPassword":"brand-new-pass-1"}')->assertStatus(400)->assertExactJson(['error' => 'Şifre veya doğrulama kodu hatalı.']);

    $r = a5('POST', '/api/auth/password/change', a5Native() + ['HTTP_AUTHORIZATION' => 'Bearer '.$old['accessToken']], '{"currentPassword":"correct-horse-1","newPassword":"brand-new-pass-1"}')->assertOk();
    expect(array_keys($r->json()))->toBe(['accessToken', 'expiresIn', 'refreshToken', 'user']);
    a5('GET', '/api/auth/mfa', ['HTTP_AUTHORIZATION' => 'Bearer '.$r->json('accessToken')])->assertOk();
    a5('GET', '/api/auth/mfa', ['HTTP_AUTHORIZATION' => 'Bearer '.$old['accessToken']])->assertStatus(401);
    a5('POST', '/api/auth/refresh', a5Native(), json_encode(['refreshToken' => $other['refreshToken']]))->assertStatus(401);
    a5('POST', '/api/auth/login', a5Native(), '{"email":"u5@example.com","password":"correct-horse-1"}')->assertStatus(401);
    a5('POST', '/api/auth/login', a5Native(), '{"email":"u5@example.com","password":"brand-new-pass-1"}')->assertOk();

    // Tarayici kanalinda yeni sifre cerezle doner, govdede jeton yok.
    $web = a5('POST', '/api/auth/password/change', ['HTTP_ORIGIN' => 'https://portal.otosanayide.test', 'HTTP_AUTHORIZATION' => 'Bearer '.$r->json('accessToken')], '{"currentPassword":"brand-new-pass-1","newPassword":"third-pass-word-1"}')->assertOk();
    expect($web->json())->not->toHaveKey('refreshToken')->and($web->headers->getCookies()[0]->getName())->toBe('otosanayide_portal_refresh');
});

it('requires the TOTP code when two-step verification is on', function () {
    $s = a5Login();
    $secret = 'JBSWY3DPEHPK3PXP';
    DB::table('auth_mfa_factors')->insert(['id' => 'f1', 'account_id' => 'u5', 'type' => 'totp', 'secret_ciphertext' => Crypt::encryptString($secret), 'verified_at' => '2026-11-10 12:00:00.000']);
    $auth = a5Native() + ['HTTP_AUTHORIZATION' => 'Bearer '.$s['accessToken']];
    a5('POST', '/api/auth/password/change', $auth, '{"currentPassword":"correct-horse-1","newPassword":"brand-new-pass-1"}')->assertStatus(403)->assertExactJson(['error' => 'İki adımlı doğrulama kodu gerekli.']);
    a5('POST', '/api/auth/password/change', $auth, '{"currentPassword":"correct-horse-1","newPassword":"brand-new-pass-1","code":"000000"}')->assertStatus(400);
    $code = Totp::code($secret, intdiv(time(), 30));
    a5('POST', '/api/auth/password/change', $auth, json_encode(['currentPassword' => 'correct-horse-1', 'newPassword' => 'brand-new-pass-1', 'code' => $code]))->assertOk();
    expect(DB::table('auth_sessions')->whereNull('revoked_at')->value('second_factor'))->toBe('totp');
});

it('validates the password change request before touching the account', function () {
    a5('POST', '/api/auth/password/change', [], '{}')->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    a5('POST', '/api/auth/password/change', a5Native() + ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    a5('POST', '/api/auth/password/change', a5Native(), '{}')->assertStatus(401);
    $s = a5Login();
    a5('POST', '/api/auth/password/change', a5Native() + ['HTTP_AUTHORIZATION' => 'Bearer '.$s['accessToken']], '{"currentPassword":"correct-horse-1","newPassword":"short"}')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
});

it('marks only older unread notifications of the caller as read and filters by kind', function () {
    $s = a5Login();
    $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$s['accessToken']];
    DB::table('notifications')->insert([
        ['id' => 'n1', 'audience_key' => 'user:u5', 'request_id' => 'q1', 'request_status' => 'new', 'kind' => null, 'created_at_ms' => 1000],
        ['id' => 'n2', 'audience_key' => 'user:u5', 'request_id' => null, 'request_status' => null, 'kind' => 'reminder', 'created_at_ms' => 2000],
        ['id' => 'n3', 'audience_key' => 'user:u5', 'request_id' => 'q2', 'request_status' => 'new', 'kind' => null, 'created_at_ms' => 9000],
        ['id' => 'n4', 'audience_key' => 'user:someone', 'request_id' => 'q3', 'request_status' => 'new', 'kind' => null, 'created_at_ms' => 1000],
    ]);
    a5('GET', '/api/notifications?kind=reminder', $auth)->assertOk()->assertJsonPath('items.0.id', 'n2')->assertJsonCount(1, 'items');
    expect(collect(a5('GET', '/api/notifications?kind=request', $auth)->json('items'))->pluck('id')->all())->toBe(['n1', 'n3']);
    a5('GET', '/api/notifications?kind=other', $auth)->assertStatus(400);

    a5('POST', '/api/notifications/read-all', $auth, '{"before":5000}')->assertOk()->assertExactJson(['updated' => 2, 'more' => false]);
    a5('POST', '/api/notifications/read-all', $auth, '{"before":5000}')->assertOk()->assertExactJson(['updated' => 0, 'more' => false]);
    $read = collect(a5('GET', '/api/notifications', $auth)->json('items'))->pluck('read', 'id')->all();
    expect($read)->toBe(['n1' => true, 'n2' => true, 'n3' => false])
        ->and(DB::table('notification_reads')->where('notification_id', 'n4')->exists())->toBeFalse();

    a5('POST', '/api/notifications/read-all', $auth, '{"before":-1}')->assertStatus(400);
    a5('POST', '/api/notifications/read-all', $auth, '{"before":1,"x":1}')->assertStatus(400);
    a5('POST', '/api/notifications/read-all', $auth + ['CONTENT_TYPE' => 'text/plain'], '{"before":1}')->assertStatus(415);
    DB::table('account_fences')->insert(['owner_uid' => 'u5', 'state' => 'deleting']);
    a5('POST', '/api/notifications/read-all', $auth, '{"before":5000}')->assertStatus(409);
});
