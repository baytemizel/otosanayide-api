<?php

namespace App\Support;

/** Kaynaktaki garageOriginAllowed() birebir; sunucu origin'i istekten degil yapilandirmadan gelir. */
final class Origin
{
    public static function allowed(?string $origin, string $serverOrigin, ?string $configuredOrigin = null): bool
    {
        if ($origin === null) {
            return true; // Native istemciler yine dogrulanmis bearer jeton ister.
        }
        $source = self::parse($origin);
        $server = self::parse($serverOrigin);
        if ($source === null || $server === null || $source['origin'] !== $origin) {
            return false;
        }
        if ($origin === $server['origin']) {
            return true;
        }
        if ($configuredOrigin !== null && $configuredOrigin !== '' && self::validApiOrigin($configuredOrigin) && $origin === $configuredOrigin) {
            return true;
        }
        $loopback = ['localhost', '127.0.0.1', '[::1]'];

        return in_array($source['host'], $loopback, true)
            && in_array($server['host'], $loopback, true)
            && $source['scheme'] === $server['scheme']
            && $source['port'] === $server['port'];
    }

    /**
     * PORTAL_WEB_ORIGIN (Sozlesme Eki v1): isletme paneli. Gecerli tam origin olmali (yol yok, sonda / yok);
     * https zorunlu, yalniz loopback gelistirme adresinde http kabul edilir. Gecersiz veya web ile ayni
     * deger yok sayilir: portal hicbir zaman web'in yerine gecmez.
     */
    public static function portal(): ?string
    {
        $value = config('otosanayide.portal_web_origin');
        if (! is_string($value) || $value === '' || $value === config('otosanayide.public_web_origin')) {
            return null;
        }
        $parsed = self::parse($value);
        if ($parsed === null || $parsed['origin'] !== $value) {
            return null;
        }
        $loopback = in_array($parsed['host'], ['localhost', '127.0.0.1', '[::1]'], true);

        return $parsed['scheme'] === 'https' || $loopback ? $value : null;
    }

    public static function isPortal(?string $origin): bool
    {
        $portal = self::portal();

        return $origin !== null && $portal !== null && $origin === $portal;
    }

    /** garageApiUrl(): https, kimlik bilgisi/yol/sorgu/parca yok. */
    private static function validApiOrigin(string $origin): bool
    {
        $u = parse_url($origin);
        if ($u === false || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) {
            return false;
        }

        return ! isset($u['user']) && ! isset($u['pass']) && ($u['path'] ?? '/') === '/' && ! isset($u['query']) && ! isset($u['fragment']);
    }

    /** @return array{origin:string,scheme:string,host:string,port:string}|null */
    private static function parse(string $value): ?array
    {
        $u = parse_url($value);
        if ($u === false || empty($u['scheme']) || empty($u['host'])) {
            return null;
        }
        $scheme = strtolower($u['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower($u['host']);
        $default = $scheme === 'https' ? 443 : 80;
        $port = isset($u['port']) && $u['port'] !== $default ? (string) $u['port'] : '';

        return ['origin' => $scheme.'://'.$host.($port !== '' ? ':'.$port : ''), 'scheme' => $scheme, 'host' => $host, 'port' => $port];
    }
}
