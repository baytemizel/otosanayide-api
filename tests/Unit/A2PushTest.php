<?php

use App\Domain\Push\ApnsPushSender;
use App\Domain\Push\FcmPushSender;
use App\Domain\Push\Jwt;
use App\Domain\Push\LogPushSender;
use App\Domain\Push\PushMessage;
use App\Domain\Push\PushSenders;
use App\Domain\Push\PushUncertain;
use App\Domain\User\PushDevices;
use App\Support\ZodError;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

// A2: push sozlesmesi ve suruculer. Gercek APNs/FCM'e baglanilmaz (Http::fake); sozlesme testidir, saglayici kabulu DEGILDIR.

const A2_UUID = '11111111-1111-4111-8111-111111111111';

function a2Register(array $over = []): stdClass
{
    return (object) ($over + ['action' => 'register', 'deviceId' => A2_UUID, 'token' => str_repeat('0a', 32), 'provider' => 'apns', 'platform' => 'ios', 'locale' => 'tr', 'appId' => 'com.otosanayide.app', 'environment' => 'production']);
}

function a2Message(string $provider = 'apns', string $env = 'production'): PushMessage
{
    return new PushMessage($provider, $provider === 'apns' ? str_repeat('0a', 32) : 'fcm-token-123456789012345', $provider === 'apns' ? 'com.otosanayide.app' : '1:123:android:abc', $provider === 'apns' ? $env : null, 'tr', str_repeat('k', 64), 'Otosanayide', 'Araç bakım veya muayene hatırlatmanı kontrol et.');
}

function a2Files(): array
{
    $dir = sys_get_temp_dir().'/a2push-'.getmypid();
    @mkdir($dir, 0700, true);
    $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($ec, $ecPem);
    file_put_contents($dir.'/AuthKey.p8', $ecPem);
    $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsa, $rsaPem);
    file_put_contents($dir.'/sa.json', json_encode(['type' => 'service_account', 'project_id' => 'otosanayide-test', 'client_email' => 'push@otosanayide-test.iam.gserviceaccount.com', 'private_key' => $rsaPem, 'token_uri' => 'https://oauth2.googleapis.com/token']));

    return ['dir' => $dir, 'ec' => $ec, 'rsa' => $rsa];
}

it('keeps the source FCM registration and accepts direct APNs registration', function () {
    $fcm = (object) ['action' => 'register', 'deviceId' => A2_UUID, 'token' => 'fcm:token_abcdefghijklmnop', 'provider' => 'fcm', 'platform' => 'android', 'locale' => 'en', 'appId' => '1:123:android:abc123'];
    expect(PushDevices::command()->parse($fcm)['provider'])->toBe('fcm')
        ->and(PushDevices::command()->parse(a2Register())['environment'])->toBe('production');
});

it('rejects malformed native registrations', function (array $over) {
    expect(fn () => PushDevices::command()->parse(a2Register($over)))->toThrow(ZodError::class);
})->with([
    'apns on android' => [['platform' => 'android']],
    'upper-case hex' => [['token' => str_repeat('0A', 32)]],
    'short token' => [['token' => str_repeat('0a', 31)]],
    'fcm-style token' => [['token' => 'fcm:token_abcdefghijklmnop']],
    'missing environment' => [['environment' => null]],
    'firebase app id' => [['appId' => '1:123:ios:abc']],
    'single label bundle' => [['appId' => 'otosanayide']],
    'extra field' => [['extra' => 1]],
    'fcm with environment' => [['provider' => 'fcm', 'token' => 'fcm:token_abcdefghijklmnop', 'appId' => '1:123:ios:abc']],
]);

it('still enforces the app id allowlist before any database write', function () {
    expect(fn () => PushDevices::write('u1', a2Register(), ['1:123:ios:abc']))->toThrow(RuntimeException::class, 'push_app');
});

it('signs ES256 and RS256 tokens that verify', function () {
    $f = a2Files();
    openssl_pkey_export($f['ec'], $ecPem);
    $jwt = Jwt::sign(['alg' => 'ES256', 'kid' => 'ABC1234567'], ['iss' => 'TEAM123456', 'iat' => 1], $ecPem);
    [$h, $c, $sig] = explode('.', $jwt);
    $raw = base64_decode(strtr($sig, '-_', '+/'));
    expect(strlen($raw))->toBe(64);
    $der = (function (string $r, string $s) {
        $enc = function (string $v): string {
            $v = ltrim($v, "\x00");
            if ($v === '' || (ord($v[0]) & 0x80)) {
                $v = "\x00".$v;
            }

            return "\x02".chr(strlen($v)).$v;
        };
        $body = $enc($r).$enc($s);

        return "\x30".chr(strlen($body)).$body;
    })(substr($raw, 0, 32), substr($raw, 32));
    expect(openssl_verify($h.'.'.$c, $der, openssl_pkey_get_details($f['ec'])['key'], OPENSSL_ALGO_SHA256))->toBe(1);

    openssl_pkey_export($f['rsa'], $rsaPem);
    [$h, $c, $sig] = explode('.', Jwt::sign(['alg' => 'RS256', 'typ' => 'JWT'], ['iss' => 'x'], $rsaPem));
    expect(openssl_verify($h.'.'.$c, base64_decode(strtr($sig, '-_', '+/')), openssl_pkey_get_details($f['rsa'])['key'], OPENSSL_ALGO_SHA256))->toBe(1);
    expect(fn () => Jwt::sign(['alg' => 'HS256'], [], $rsaPem))->toThrow(RuntimeException::class);
});

it('sends through APNs over the right host and maps terminal answers', function () {
    $f = a2Files();
    $apns = new ApnsPushSender($f['dir'].'/AuthKey.p8', 'ABC1234567', 'TEAM123456', fn () => 1_800_000_000);
    Http::fake(['api.push.apple.com/*' => Http::response('', 200, ['apns-id' => 'apns-uuid-1']), 'api.sandbox.push.apple.com/*' => Http::response(['reason' => 'BadDeviceToken'], 400)]);

    expect($apns->send(a2Message())->status)->toBe('accepted');
    expect($apns->send(a2Message('apns', 'sandbox'))->status)->toBe('invalid_token');
    Http::assertSent(function (Request $r) {
        return str_starts_with($r->url(), 'https://api.push.apple.com/3/device/'.str_repeat('0a', 32))
            && $r->header('apns-topic') === ['com.otosanayide.app'] && $r->header('apns-push-type') === ['alert']
            && str_starts_with($r->header('authorization')[0], 'bearer ') && $r->header('apns-collapse-id') === ['otosanayide-reminder']
            && $r['aps']['alert']['body'] === 'Araç bakım veya muayene hatırlatmanı kontrol et.' && $r['route'] === '/hesabim';
    });
});

it('treats APNs 410 as invalid and everything unclear as uncertain', function () {
    $f = a2Files();
    $apns = new ApnsPushSender($f['dir'].'/AuthKey.p8', 'ABC1234567', 'TEAM123456');
    Http::fakeSequence('api.push.apple.com/*')->push(['reason' => 'Unregistered'], 410)->push(['reason' => 'TooManyRequests'], 429)->push(['reason' => 'InternalServerError'], 500)->pushFailedConnection();
    expect($apns->send(a2Message())->status)->toBe('invalid_token');
    foreach ([1, 2, 3] as $_) {
        expect(fn () => $apns->send(a2Message()))->toThrow(PushUncertain::class);
    }
    expect(fn () => new ApnsPushSender('/x', 'bad', 'TEAM123456'))->toThrow(RuntimeException::class);
});

it('sends through FCM HTTP v1 with a service account and maps terminal answers', function () {
    $f = a2Files();
    $fcm = new FcmPushSender($f['dir'].'/sa.json', null, fn () => 1_800_000_000);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'projects/otosanayide-test/messages/1'])->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404)->push(['error' => ['status' => 'UNAVAILABLE']], 503),
    ]);
    expect($fcm->send(a2Message('fcm'))->status)->toBe('accepted')
        ->and($fcm->send(a2Message('fcm'))->status)->toBe('invalid_token');
    expect(fn () => $fcm->send(a2Message('fcm')))->toThrow(PushUncertain::class);
    Http::assertSentCount(4); // erisim jetonu bir kez alinir
    Http::assertSent(fn (Request $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/otosanayide-test/messages:send'
        && $r->header('Authorization') === ['Bearer ya29.test'] && $r['message']['token'] === 'fcm-token-123456789012345'
        && $r['message']['data'] === ['route' => '/hesabim'] && $r['message']['android']['ttl'] === '86400s');
});

it('refuses a tampered service account token uri', function () {
    $f = a2Files();
    $raw = json_decode((string) file_get_contents($f['dir'].'/sa.json'), true);
    file_put_contents($f['dir'].'/evil.json', json_encode(['token_uri' => 'https://evil.test/token'] + $raw));
    Http::fake();
    expect(fn () => (new FcmPushSender($f['dir'].'/evil.json'))->send(a2Message('fcm')))->toThrow(RuntimeException::class, 'push_fcm_config');
    Http::assertNothingSent();
});

it('defaults to the log driver, refuses it in production and picks live drivers per provider', function () {
    expect((new PushSenders)->for('apns'))->toBeInstanceOf(LogPushSender::class);
    App::detectEnvironment(fn () => 'production');
    expect(fn () => (new LogPushSender)->send(a2Message()))->toThrow(RuntimeException::class, 'push_log_driver_in_production');
    $f = a2Files();
    config(['otosanayide.push.driver' => 'live', 'otosanayide.push.apns' => ['key_path' => $f['dir'].'/AuthKey.p8', 'key_id' => 'ABC1234567', 'team_id' => 'TEAM123456'], 'otosanayide.push.fcm' => ['credentials_path' => $f['dir'].'/sa.json', 'project_id' => null]]);
    expect((new PushSenders)->for('apns'))->toBeInstanceOf(ApnsPushSender::class)
        ->and((new PushSenders)->for('fcm'))->toBeInstanceOf(FcmPushSender::class);
    config(['otosanayide.push.driver' => 'nope']);
    expect(fn () => (new PushSenders)->for('fcm'))->toThrow(RuntimeException::class, 'push_driver');
});

afterAll(function () {
    foreach (glob(sys_get_temp_dir().'/a2push-'.getmypid().'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(sys_get_temp_dir().'/a2push-'.getmypid());
});
