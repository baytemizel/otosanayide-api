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

    public static function trim(string $value): string
    {
        return (string) preg_replace('/^['.self::WS.']+|['.self::WS.']+$/u', '', $value);
    }
}
