<?php

namespace App\Domain\Payments;

use App\Domain\Content\Ids;
use App\Support\Z;
use App\Support\ZodError;
use Closure;
use Illuminate\Support\Facades\DB;
use stdClass;

/** F7 ortak yardimcilari: Istanbul gun hesabi, JS sayi kurallari, kilitli satir okumalari. */
final class F7
{
    public const DAY = 86400000;

    public const SAFE = 9007199254740991;

    public static function flag(string $name): bool
    {
        return config('otosanayide.flags.'.$name) === true;
    }

    public static function nowMs(): int
    {
        return Ids::nowMs();
    }

    /** Date.parse(date+'T00:00:00+03:00'); gecersizse null (NaN). */
    public static function trStart(string $date): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return (gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) * 1000) - 3 * 3600000;
    }

    /** Date.parse(date+'T00:00:00Z'). */
    public static function utcStart(string $date): ?int
    {
        $tr = self::trStart($date);

        return $tr === null ? null : $tr + 3 * 3600000;
    }

    /** new Date(ms).toISOString().slice(0,10) */
    public static function isoDate(int $ms): string
    {
        return gmdate('Y-m-d', intdiv($ms - (($ms % 1000 + 1000) % 1000), 1000));
    }

    /** Istanbul takvim gunu. */
    public static function trDate(int $now): string
    {
        return self::isoDate($now + 10800000);
    }

    /** z.string().regex(/^\d{4}-\d{2}-\d{2}$/).refine(gercek tarih) */
    public static function date(): Z
    {
        return Z::string()->regex('/^\d{4}-\d{2}-\d{2}$/')->refine(static fn (string $v): bool => self::trStart($v) !== null);
    }

    public static function uuid(): Z
    {
        return Z::string()->uuid();
    }

    public static function hash(): Z
    {
        return Z::string()->regex('/^[a-f0-9]{64}$/');
    }

    public static function isUuid(mixed $v): bool
    {
        return self::ok(self::uuid(), $v);
    }

    public static function isHash(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[a-f0-9]{64}$/D', $v) === 1;
    }

    public static function safeInt(mixed $v): bool
    {
        return is_int($v) && abs($v) <= self::SAFE;
    }

    public static function ok(Z $schema, mixed $v): bool
    {
        try {
            $schema->parse($v);

            return true;
        } catch (ZodError) {
            return false;
        }
    }

    /** JSON.stringify degeri (Unicode kacirilmaz). */
    public static function json(mixed $v): string
    {
        return Ids::stringify($v);
    }

    /** createHash('sha256').update(JSON.stringify(v)).digest('hex') */
    public static function sha(mixed $v): string
    {
        return hash('sha256', self::json($v));
    }

    /** Math.round (yarim yukari) pozitif tutarlar icin. */
    public static function round(float|int $v): int
    {
        return (int) floor($v + 0.5);
    }

    /** JS sayisi gibi: tam sayiya esit float'u int yap. */
    public static function num(float|int $v): float|int
    {
        return is_float($v) && $v == floor($v) && abs($v) <= self::SAFE ? (int) $v : $v;
    }

    public static function lock(string $table, string $column, string $id): ?stdClass
    {
        return DB::table($table)->where($column, $id)->lockForUpdate()->first();
    }

    public static function row(string $table, string $column, string $id): ?stdClass
    {
        return DB::table($table)->where($column, $id)->first();
    }

    /** @return list<mixed>|null */
    public static function list(mixed $v): ?array
    {
        if (is_string($v)) {
            $v = json_decode($v, true);
        }

        return is_array($v) && array_is_list($v) ? $v : null;
    }

    /** Body::json stdClass agacini dizi kayda cevirir. */
    public static function plain(mixed $v): mixed
    {
        return json_decode((string) json_encode($v), true);
    }

    /**
     * @param  Closure():mixed  $run
     */
    public static function attempt(Closure $run): bool
    {
        try {
            $run();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
