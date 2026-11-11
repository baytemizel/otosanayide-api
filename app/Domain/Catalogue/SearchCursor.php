<?php

namespace App\Domain\Catalogue;

/** Arama v2 imleci: base64url({o,f}) + "." + HMAC-SHA256(APP_KEY). Filtre ozeti degisirse gecersiz. */
final class SearchCursor
{
    public static function seal(int $offset, string $digest): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode(['o' => $offset, 'f' => substr($digest, 0, 32)])), '+/', '-_'), '=');

        return $body.'.'.self::mac($body);
    }

    public static function open(string $cursor, string $digest): ?int
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $parts[0]) !== 1 || ! hash_equals(self::mac($parts[0]), $parts[1])) {
            return null;
        }
        $c = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($c) || ! is_int($c['o'] ?? null) || ($c['f'] ?? null) !== substr($digest, 0, 32) || $c['o'] < 1 || $c['o'] > SearchService::MAX_OFFSET || $c['o'] % SearchService::PAGE_LIMIT !== 0) {
            return null;
        }

        return $c['o'];
    }

    private static function mac(string $body): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'search-cursor-v1.'.$body, (string) config('app.key'), true)), '+/', '-_'), '=');
    }
}
