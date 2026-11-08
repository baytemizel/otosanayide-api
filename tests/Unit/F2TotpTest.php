<?php

use App\Domain\Auth\AuthService;
use App\Domain\Auth\Totp;

// RFC 6238 Ek B, SHA-1 anahtari "12345678901234567890" (8 hane yerine son 6 hane).
$secret = Totp::base32('12345678901234567890');

it('produces RFC 6238 test vectors', function (int $time, string $expected) use ($secret) {
    expect(Totp::code($secret, intdiv($time, 30)))->toBe($expected);
})->with([[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037']]);

it('accepts one step of skew and refuses replay', function () use ($secret) {
    $code = Totp::code($secret, 1000);
    expect(Totp::matchStep($secret, $code, 30000, null))->toBe(1000)
        ->and(Totp::matchStep($secret, $code, 30030, null))->toBe(1000)
        ->and(Totp::matchStep($secret, $code, 30090, null))->toBeNull()
        ->and(Totp::matchStep($secret, $code, 30000, 1000))->toBeNull()
        ->and(Totp::matchStep($secret, 'abc123', 30000, null))->toBeNull();
});

it('uses argon2id with the documented parameters and a matching dummy hash', function () {
    expect(config('hashing.driver'))->toBe('argon2id');
    $info = password_get_info(AuthService::DUMMY_HASH);
    expect($info['algoName'])->toBe('argon2id')
        ->and($info['options']['memory_cost'])->toBe(config('hashing.argon.memory'))
        ->and($info['options']['time_cost'])->toBe(config('hashing.argon.time'))
        ->and($info['options']['threads'])->toBe(config('hashing.argon.threads'));
});
