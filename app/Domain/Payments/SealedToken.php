<?php

namespace App\Domain\Payments;

use RuntimeException;
use stdClass;

/** AES-256-GCM jeton: iv(12) + tag(16) + sifreli JSON, base64url (dolgusuz). Anahtar 64 hex karakter. */
final class SealedToken
{
    public static function key(?string $secret): string
    {
        if (! is_string($secret) || preg_match('/^[a-fA-F0-9]{64}$/D', $secret) !== 1) {
            throw new RuntimeException('configuration');
        }

        return (string) hex2bin($secret);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function seal(array $payload, ?string $secret, string $aad): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $data = openssl_encrypt(F7::json($payload), 'aes-256-gcm', self::key($secret), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($data === false) {
            throw new RuntimeException('token');
        }

        return self::b64($iv.$tag.$data);
    }

    /** Buffer.from(token,'base64url') + yeniden kodlama esitligi; cozulen JSON stdClass olarak doner. */
    public static function open(string $token, ?string $secret, string $aad, int $maxBytes = PHP_INT_MAX): stdClass
    {
        $key = self::key($secret);
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $token) !== 1) {
            throw new RuntimeException('token');
        }
        $bytes = base64_decode(strtr($token, '-_', '+/'), false);
        if (strlen($bytes) < 29 || strlen($bytes) > $maxBytes || self::b64($bytes) !== $token) {
            throw new RuntimeException('token');
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), $aad);
        if ($plain === false) {
            throw new RuntimeException('token');
        }
        $v = json_decode($plain, false, 32, JSON_THROW_ON_ERROR);
        if (! $v instanceof stdClass) {
            throw new RuntimeException('token');
        }

        return $v;
    }
}
