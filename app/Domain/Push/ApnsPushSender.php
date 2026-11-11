<?php

namespace App\Domain\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * APNs token tabanli kimlik (.p8, ES256) ve HTTP/2. KOD HAZIR; gercek APNs'e karsi CALISTIRILMADI.
 * Jeton yalniz Http istegine gider; log veya istisna mesajina girmez.
 */
final class ApnsPushSender implements PushSender
{
    private const HOSTS = ['production' => 'https://api.push.apple.com', 'sandbox' => 'https://api.sandbox.push.apple.com'];

    private const INVALID = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'];

    private ?string $jwt = null;

    private int $issuedAt = 0;

    public function __construct(private readonly string $keyPath, private readonly string $keyId, private readonly string $teamId, private readonly ?\Closure $clock = null)
    {
        if (preg_match('/^[A-Z0-9]{10}$/D', $keyId) !== 1 || preg_match('/^[A-Z0-9]{10}$/D', $teamId) !== 1) {
            throw new RuntimeException('push_apns_config');
        }
    }

    public function bindingDigest(): string
    {
        return hash('sha256', json_encode(['provider' => 'apns', 'team' => $this->teamId, 'key' => $this->keyId, 'contract' => 'push-v1']) ?: '');
    }

    public function send(PushMessage $message): PushResult
    {
        if ($message->provider !== 'apns' || ! isset(self::HOSTS[(string) $message->environment])) {
            throw new RuntimeException('push_apns_message');
        }
        $now = $this->now();
        $payload = ['aps' => ['alert' => ['title' => $message->title, 'body' => $message->body], 'sound' => 'default'], 'route' => $message->route];
        try {
            $response = Http::withOptions(['version' => 2.0])->timeout(10)->connectTimeout(5)->withHeaders([
                'authorization' => 'bearer '.$this->token($now),
                'apns-topic' => $message->appId,
                'apns-push-type' => 'alert',
                'apns-priority' => '5',
                'apns-expiration' => (string) ($now + $message->ttlSeconds),
                'apns-collapse-id' => $message->collapseKey,
            ])->withBody((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')
                ->post(self::HOSTS[$message->environment].'/3/device/'.$message->token);
        } catch (ConnectionException) {
            throw new PushUncertain('push_provider_uncertain');
        } catch (Throwable) {
            throw new PushUncertain('push_provider_uncertain');
        }
        if ($response->status() === 200) {
            return PushResult::accepted((string) ($response->header('apns-id') ?: 'apns'));
        }
        $reason = $response->json('reason');
        if ($response->status() === 410 || (in_array($response->status(), [400, 403], true) && in_array($reason, self::INVALID, true))) {
            return PushResult::invalidToken();
        }
        throw new PushUncertain('push_provider_uncertain');
    }

    /** Apple: jeton en az 20 dk, en fazla 60 dk kullanilir; 50 dk'da yenilenir. */
    private function token(int $now): string
    {
        if ($this->jwt !== null && $now - $this->issuedAt < 3000) {
            return $this->jwt;
        }
        $pem = is_file($this->keyPath) && is_readable($this->keyPath) ? (string) file_get_contents($this->keyPath) : '';
        $this->jwt = Jwt::sign(['alg' => 'ES256', 'kid' => $this->keyId], ['iss' => $this->teamId, 'iat' => $now], $pem);
        $this->issuedAt = $now;

        return $this->jwt;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
