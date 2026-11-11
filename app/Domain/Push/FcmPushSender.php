<?php

namespace App\Domain\Push;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * FCM HTTP v1, servis hesabi (RS256 JWT -> OAuth2 erisim jetonu). KOD HAZIR; gercek FCM'e karsi CALISTIRILMADI.
 * token_uri dosyadan okunur ama yalniz Google'in sabit adresi kabul edilir (degistirilmis dosya istegi yonlendiremez).
 */
final class FcmPushSender implements PushSender
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?string $access = null;

    private int $expiresAt = 0;

    /** @var array{client_email:string,private_key:string,project_id:string}|null */
    private ?array $account = null;

    public function __construct(private readonly string $credentialsPath, private readonly ?string $projectId = null, private readonly ?\Closure $clock = null) {}

    public function bindingDigest(): string
    {
        return hash('sha256', json_encode(['provider' => 'fcm-v1', 'project' => $this->account()['project_id'], 'contract' => 'push-v1']) ?: '');
    }

    public function send(PushMessage $message): PushResult
    {
        if ($message->provider !== 'fcm') {
            throw new RuntimeException('push_fcm_message');
        }
        $account = $this->account();
        $body = ['message' => [
            'token' => $message->token,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => ['route' => $message->route],
            'android' => ['collapse_key' => $message->collapseKey, 'priority' => 'normal', 'ttl' => $message->ttlSeconds.'s', 'notification' => ['tag' => $message->collapseKey, 'visibility' => 'PRIVATE']],
            'apns' => ['headers' => ['apns-collapse-id' => $message->collapseKey, 'apns-expiration' => (string) ($this->now() + $message->ttlSeconds)], 'payload' => ['aps' => ['sound' => 'default']]],
        ]];
        $access = $this->accessToken();
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withToken($access)->asJson()
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($account['project_id']).'/messages:send', $body);
        } catch (Throwable) {
            throw new PushUncertain('push_provider_uncertain');
        }
        if ($response->successful() && is_string($response->json('name'))) {
            return PushResult::accepted((string) $response->json('name'));
        }
        $codes = collect((array) $response->json('error.details'))->pluck('errorCode')->filter()->all();
        if ($response->status() === 404 && in_array('UNREGISTERED', $codes, true)) {
            return PushResult::invalidToken();
        }
        // INVALID_ARGUMENT yalniz jeton alanina isaret ediyorsa kesin ret; yuk hatasi belirsiz kalir.
        if ($response->status() === 400 && in_array('INVALID_ARGUMENT', $codes, true) && str_contains((string) $response->json('error.message'), 'registration token')) {
            return PushResult::invalidToken();
        }
        throw new PushUncertain('push_provider_uncertain');
    }

    /** Erisim jetonu yalniz surec belleginde tutulur (veritabani/onbellege yazilmaz). */
    private function accessToken(): string
    {
        $now = $this->now();
        if ($this->access !== null && $now < $this->expiresAt - 300) {
            return $this->access;
        }
        $account = $this->account();
        $assertion = Jwt::sign(['alg' => 'RS256', 'typ' => 'JWT'], ['iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URI, 'iat' => $now, 'exp' => $now + 3600], $account['private_key']);
        try {
            $response = Http::timeout(10)->connectTimeout(5)->asForm()->post(self::TOKEN_URI, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
        } catch (Throwable) {
            throw new RuntimeException('push_fcm_auth'); // Gonderimden once: hicbir sey gonderilmedi.
        }
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('push_fcm_auth');
        }
        $this->access = $token;
        $this->expiresAt = $now + (int) ($response->json('expires_in') ?? 3600);

        return $token;
    }

    /** @return array{client_email:string,private_key:string,project_id:string} */
    private function account(): array
    {
        if ($this->account !== null) {
            return $this->account;
        }
        $raw = is_file($this->credentialsPath) && is_readable($this->credentialsPath) ? json_decode((string) file_get_contents($this->credentialsPath), true) : null;
        $project = $this->projectId ?: (is_array($raw) ? ($raw['project_id'] ?? null) : null);
        if (! is_array($raw) || ($raw['type'] ?? null) !== 'service_account' || ! is_string($raw['client_email'] ?? null) || ! is_string($raw['private_key'] ?? null)
            || ($raw['token_uri'] ?? self::TOKEN_URI) !== self::TOKEN_URI || ! is_string($project) || preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/D', $project) !== 1) {
            throw new RuntimeException('push_fcm_config');
        }

        return $this->account = ['client_email' => $raw['client_email'], 'private_key' => $raw['private_key'], 'project_id' => $project];
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
