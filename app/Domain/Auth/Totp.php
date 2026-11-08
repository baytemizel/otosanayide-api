<?php

namespace App\Domain\Auth;

/** RFC 6238 TOTP: SHA-1, 6 hane, 30 sn, +/-1 adim. */
final class Totp
{
    public const PERIOD = 30;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function code(string $base32, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($base32), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Eslesen adimi dondurur; lastUsedStep ve oncesi kabul edilmez (tekrar kullanim engeli). */
    public static function matchStep(string $base32, string $code, int $time, ?int $lastUsedStep): ?int
    {
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return null;
        }
        $now = intdiv($time, self::PERIOD);
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if ($step >= 0 && ($lastUsedStep === null || $step > $lastUsedStep) && hash_equals(self::code($base32, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $email): string
    {
        return 'otpauth://totp/'.rawurlencode('Otosanayide:'.$email).'?'.http_build_query(['secret' => $secret, 'issuer' => 'Otosanayide', 'algorithm' => 'SHA1', 'digits' => 6, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function decode(string $base32): string
    {
        $bits = '';
        foreach (str_split(strtoupper($base32)) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
