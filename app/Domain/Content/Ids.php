<?php

namespace App\Domain\Content;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Kimlik ve zaman bicimleri kaynaktakiyle ayni tutulur. */
final class Ids
{
    private const AUTO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** createHash('sha256').update(JSON.stringify(value)).digest('hex') */
    public static function sha(array $value): string
    {
        return hash('sha256', self::stringify($value));
    }

    /** JSON.stringify: Unicode ve / kacirilmaz; U+2028/9 da oyle. */
    public static function stringify(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /** Firestore doc() otomatik kimligi: 20 alfanumerik. */
    public static function auto(): string
    {
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= self::AUTO[random_int(0, 61)];
        }

        return $out;
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** FieldValue.serverTimestamp(): veritabani saati. */
    public static function now(): Expression
    {
        return DB::raw('CURRENT_TIMESTAMP(3)');
    }

    /** sha256 hex (views/{sha256(uid)} anahtari). */
    public static function viewKey(string $uid): string
    {
        return hash('sha256', $uid);
    }
}
