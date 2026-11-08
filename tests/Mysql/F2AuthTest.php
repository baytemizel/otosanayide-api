<?php

use App\Domain\Auth\Totp;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Jobs\SendAuthEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function authPost($test, string $uri, array $body, array $server = [])
{
    return $test->call('POST', $uri, [], [], [], $server + ['HTTP_ACCEPT_LANGUAGE' => '', 'CONTENT_TYPE' => 'application/json'], json_encode($body));
}

function newAccount($test, string $email = 'user@example.com', string $password = 'correct-horse-1', bool $verified = true): string
{
    Queue::fake();
    authPost($test, '/api/auth/register', ['email' => $email, 'password' => $password])->assertStatus(202);
    $id = DB::table('auth_accounts')->where('email', $email)->value('id');
    if ($verified) {
        DB::table('auth_accounts')->where('id', $id)->update(['email_verified_at' => now('UTC')]);
    }

    return $id;
}

function cookieFrom($response): string
{
    foreach ($response->headers->getCookies() as $c) {
        if ($c->getName() === AuthController::COOKIE) {
            return $c->getValue();
        }
    }
    throw new RuntimeException('no cookie');
}

function refreshWith($test, string $raw)
{
    return $test->call('POST', '/api/auth/refresh', [], [AuthController::COOKIE => $raw], [], ['HTTP_ACCEPT_LANGUAGE' => '']);
}

it('stores only hashes and marks the cookie HttpOnly Secure Strict', function () {
    newAccount($this);
    $r = authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'correct-horse-1'])->assertOk();
    $raw = cookieFrom($r);
    $c = $r->headers->getCookies()[0];
    expect($c->isHttpOnly())->toBeTrue()->and($c->isSecure())->toBeTrue()->and($c->getSameSite())->toBe('strict');
    expect(DB::table('auth_sessions')->where('refresh_token_hash', $raw)->exists())->toBeFalse()
        ->and(DB::table('auth_sessions')->where('refresh_token_hash', hash('sha256', $raw))->exists())->toBeTrue();
    expect(DB::table('auth_accounts')->value('password_hash'))->toStartWith('$argon2id$');
});

it('does not reveal whether an email exists', function () {
    newAccount($this);
    Queue::fake();
    $known = authPost($this, '/api/auth/register', ['email' => 'user@example.com', 'password' => 'another-pass-1']);
    $unknown = authPost($this, '/api/auth/register', ['email' => 'new@example.com', 'password' => 'another-pass-1']);
    expect($known->status())->toBe($unknown->status())->and($known->getContent())->toBe($unknown->getContent());

    $wrong = authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-password']);
    $absent = authPost($this, '/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
    expect($wrong->status())->toBe(401)->and($wrong->getContent())->toBe($absent->getContent());

    $a = authPost($this, '/api/auth/password/forgot', ['email' => 'user@example.com']);
    $b = authPost($this, '/api/auth/password/forgot', ['email' => 'nobody@example.com']);
    expect($a->status())->toBe(202)->and($a->getContent())->toBe($b->getContent());
    Queue::assertPushed(SendAuthEmail::class);
});

it('rotates refresh tokens and revokes the family on reuse', function () {
    newAccount($this);
    $login = authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'correct-horse-1']);
    $first = cookieFrom($login);
    $second = refreshWith($this, $first)->assertOk();
    $next = cookieFrom($second);
    expect($next)->not->toBe($first);
    refreshWith($this, $first)->assertStatus(401);
    refreshWith($this, $next)->assertStatus(401);
    expect(DB::table('auth_sessions')->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('invalidates old access tokens and sessions after password reset', function () {
    newAccount($this);
    $login = authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'correct-horse-1']);
    $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$login->json('accessToken')];
    $sent = null;
    Queue::fake();
    authPost($this, '/api/auth/password/forgot', ['email' => 'user@example.com']);
    Queue::assertPushed(SendAuthEmail::class, function ($job) use (&$sent) {
        $sent = $job->token;

        return true;
    });
    authPost($this, '/api/auth/password/reset', ['token' => $sent, 'password' => 'brand-new-pass-1'])->assertOk();
    authPost($this, '/api/auth/password/reset', ['token' => $sent, 'password' => 'brand-new-pass-2'])->assertStatus(400);
    authPost($this, '/api/auth/email/resend', [], $bearer)->assertStatus(401);
    refreshWith($this, cookieFrom($login))->assertStatus(401);
    authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'correct-horse-1'])->assertStatus(401);
});

it('rejects expired, unknown and revoked access tokens', function () {
    newAccount($this);
    $login = authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'correct-horse-1']);
    $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$login->json('accessToken')];
    authPost($this, '/api/auth/email/resend', [], ['HTTP_AUTHORIZATION' => 'Bearer 1|nope'])->assertStatus(401);
    DB::table('personal_access_tokens')->update(['expires_at' => now('UTC')->subMinute()]);
    authPost($this, '/api/auth/email/resend', [], $bearer)->assertStatus(401)->assertExactJson(['error' => 'Oturum geçersiz veya süresi dolmuş.']);
});

it('gates unverified email with 403 and runs TOTP enrollment with replay protection', function () {
    $id = newAccount($this, verified: false);
    $login = authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'correct-horse-1']);
    $bearer = ['HTTP_AUTHORIZATION' => 'Bearer '.$login->json('accessToken')];
    authPost($this, '/api/auth/mfa/totp/enroll', [], $bearer)->assertStatus(403);
    DB::table('auth_accounts')->where('id', $id)->update(['email_verified_at' => now('UTC')]);
    $enroll = authPost($this, '/api/auth/mfa/totp/enroll', [], $bearer)->assertStatus(201);
    $secret = $enroll->json('secret');
    expect(DB::table('auth_mfa_factors')->value('secret_ciphertext'))->not->toContain($secret);
    $code = Totp::code($secret, intdiv(time(), 30));
    authPost($this, '/api/auth/mfa/totp/confirm', ['code' => $code], $bearer)->assertOk();
    $again = authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'correct-horse-1'])->assertOk();
    expect($again->json('mfaRequired'))->toBeTrue()->and($again->json('accessToken'))->toBeNull();
    // Ayni adimin kodu ikinci kez kullanilamaz.
    authPost($this, '/api/auth/mfa/verify', ['challenge' => $again->json('challenge'), 'code' => $code])->assertStatus(401);
});

it('throttles repeated logins to 429', function () {
    newAccount($this);
    foreach (range(1, 10) as $_) {
        authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-password'])->assertStatus(401);
    }
    authPost($this, '/api/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-password'])->assertStatus(429);
});
