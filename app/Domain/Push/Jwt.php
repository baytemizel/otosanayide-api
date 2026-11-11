<?php

namespace App\Domain\Push;

use RuntimeException;

/** Saglayici kimlik dogrulamasi icin en kucuk JWS imzalayici (ES256: APNs .p8, RS256: Google servis hesabi). */
final class Jwt
{
    /**
     * @param  array<string,string>  $header
     * @param  array<string,string|int>  $claims
     */
    public static function sign(array $header, array $claims, string $pem): string
    {
        $alg = $header['alg'] ?? '';
        $input = self::b64((string) json_encode($header, JSON_UNESCAPED_SLASHES)).'.'.self::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($pem);
        if ($key === false || ! in_array($alg, ['ES256', 'RS256'], true)) {
            throw new RuntimeException('push_key');
        }
        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('push_key');
        }

        return $input.'.'.self::b64($alg === 'ES256' ? self::derToRaw($signature, 32) : $signature);
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** OpenSSL ECDSA imzasi ASN.1 DER SEQUENCE{r,s}; JWS ham r||s ister. */
    public static function derToRaw(string $der, int $size): string
    {
        $offset = 0;
        $read = static function () use ($der, &$offset): string {
            if (($der[$offset] ?? '') !== "\x02") {
                throw new RuntimeException('push_signature');
            }
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            return $value;
        };
        if (($der[0] ?? '') !== "\x30") {
            throw new RuntimeException('push_signature');
        }
        $offset = ord($der[1]) & 0x80 ? 2 + (ord($der[1]) & 0x7F) : 2;
        $r = ltrim($read(), "\x00");
        $s = ltrim($read(), "\x00");
        if (strlen($r) > $size || strlen($s) > $size) {
            throw new RuntimeException('push_signature');
        }

        return str_pad($r, $size, "\x00", STR_PAD_LEFT).str_pad($s, $size, "\x00", STR_PAD_LEFT);
    }
}
