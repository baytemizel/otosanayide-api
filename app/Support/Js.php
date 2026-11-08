<?php

namespace App\Support;

/** Kaynak TypeScript oldugu icin sinirlar JS anlamiyla olculur: UTF-16 uzunluk, ECMAScript trim. */
final class Js
{
    private const WS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    public static function length(string $value): int
    {
        return (int) (strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2);
    }

    /** JSON.stringify karsiligi. */
    public static function stringify(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /** new Date().toISOString() */
    public static function iso(?\DateTimeInterface $at = null): string
    {
        $at = \Carbon\CarbonImmutable::instance($at ?? new \DateTimeImmutable('now'))->setTimezone('UTC');

        return $at->format('Y-m-d\\TH:i:s.v\\Z');
    }

    /** Date.parse */
    public static function ms(string $iso): int
    {
        return (int) floor(\Carbon\CarbonImmutable::parse($iso)->getPreciseTimestamp(3));
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function trim(string $value): string
    {
        return (string) preg_replace('/^['.self::WS.']+|['.self::WS.']+$/u', '', $value);
    }
}
