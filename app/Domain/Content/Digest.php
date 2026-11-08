<?php

namespace App\Domain\Content;

use RuntimeException;

/** erasureDigest: anahtarlari sirali JSON'un SHA-256 ozeti. */
final class Digest
{
    private const LIMIT = 4000000;

    public static function of(mixed $value): string
    {
        $raw = (string) json_encode(self::normalized($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        if (strlen($raw) > self::LIMIT) {
            throw new RuntimeException('erasure_size');
        }

        return hash('sha256', $raw);
    }

    private static function normalized(mixed $v): mixed
    {
        if ($v === null || is_string($v) || is_bool($v) || is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            if (! is_finite($v)) {
                throw new RuntimeException('erasure_value');
            }

            return $v;
        }
        if (is_object($v)) {
            $v = get_object_vars($v);
        }
        if (is_array($v)) {
            if ($v === [] || array_is_list($v)) {
                return array_map([self::class, 'normalized'], $v);
            }
            ksort($v, SORT_STRING);

            return (object) array_map([self::class, 'normalized'], $v);
        }
        throw new RuntimeException('erasure_value');
    }
}
