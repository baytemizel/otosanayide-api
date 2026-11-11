<?php

namespace App\Domain\Catalogue;

use App\Support\Z;
use DateTimeImmutable;
use DateTimeZone;

/**
 * web-07 + portal-04: yapilandirilmis calisma saatleri (Europe/Istanbul).
 * Gun ISO (1=Pazartesi..7=Pazar); listede olmayan gun kapali. Aralik ayni gun icinde: "open" < "close",
 * "close" en gec "24:00"; gece yarisini asan aralik iki gune bolunur. Gunde en cok 3 aralik, en cok 60 istisna gunu.
 * Sunucu "su an acik" HESAPLAMAZ; saat yoksa alan hic donmez, istemci acik varsaymaz.
 */
final class OpeningHours
{
    public const TIMEZONE = 'Europe/Istanbul';

    private const TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';

    public static function schema(): Z
    {
        $interval = Z::strictObject(['open' => Z::string()->regex(self::TIME), 'close' => Z::string()->regex('/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/D')]);
        $intervals = Z::array($interval)->max(3)->refine(self::validIntervals(...), 'invalid_intervals');

        return Z::strictObject([
            'timezone' => Z::literal(self::TIMEZONE),
            'week' => Z::array(Z::strictObject(['day' => Z::number()->int()->min(1)->max(7), 'intervals' => $intervals]))->max(7)
                ->refine(static fn (array $w): bool => count(array_unique(array_column($w, 'day'))) === count($w), 'duplicate_day'),
            'exceptions' => Z::array(Z::strictObject(['date' => Z::string()->isoDate(), 'intervals' => $intervals]))->max(60)
                ->refine(static fn (array $e): bool => count(array_unique(array_column($e, 'date'))) === count($e), 'duplicate_date'),
        ]);
    }

    /** @param list<array{open:string,close:string}> $list */
    public static function validIntervals(array $list): bool
    {
        usort($list, static fn (array $a, array $b): int => strcmp($a['open'], $b['open']));
        $end = '';
        foreach ($list as $i) {
            if (strcmp($i['open'], $i['close']) >= 0 || strcmp($i['open'], $end) < 0) {
                return false;
            }
            $end = $i['close'];
        }

        return true;
    }

    /**
     * Yazim oncesi kanonik bicim: gunler ve aralikler sirali; gecmis istisnalar ve 366 gunden uzak olanlar reddedilir.
     *
     * @param  array{timezone:string,week:list<array{day:int,intervals:list<array{open:string,close:string}>}>,exceptions:list<array{date:string,intervals:list<array{open:string,close:string}>}>}  $h
     * @return array<string,mixed>|null null: gecersiz tarih araligi
     */
    public static function canonical(array $h, ?DateTimeImmutable $now = null): ?array
    {
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
        $limit = (new DateTimeImmutable($today))->modify('+366 days')->format('Y-m-d');
        $sort = static function (array $list): array {
            usort($list, static fn (array $a, array $b): int => strcmp($a['open'], $b['open']));

            return array_map(static fn (array $i): array => ['open' => $i['open'], 'close' => $i['close']], $list);
        };
        $week = $h['week'];
        usort($week, static fn (array $a, array $b): int => $a['day'] <=> $b['day']);
        $exceptions = $h['exceptions'];
        foreach ($exceptions as $e) {
            if (strcmp($e['date'], $today) < 0 || strcmp($e['date'], $limit) > 0) {
                return null;
            }
        }
        usort($exceptions, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return [
            'timezone' => self::TIMEZONE,
            'week' => array_map(static fn (array $d): array => ['day' => (int) $d['day'], 'intervals' => $sort($d['intervals'])], $week),
            'exceptions' => array_map(static fn (array $e): array => ['date' => $e['date'], 'intervals' => $sort($e['intervals'])], $exceptions),
        ];
    }

    /** Okuma: bozuk kayit hic gosterilmez; gecmis istisnalar ayiklanir. @return array<string,mixed>|null */
    public static function view(?string $json, ?DateTimeImmutable $now = null): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $raw = json_decode($json, true);
        if (! is_array($raw) || ! isset($raw['week'], $raw['exceptions']) || ! is_array($raw['week']) || ! is_array($raw['exceptions'])) {
            return null;
        }
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
        $raw['exceptions'] = array_values(array_filter($raw['exceptions'], static fn ($e): bool => is_array($e) && is_string($e['date'] ?? null) && strcmp($e['date'], $today) >= 0));

        return ['timezone' => self::TIMEZONE, 'week' => $raw['week'], 'exceptions' => $raw['exceptions']] + (isset($raw['updatedAtMs']) ? ['updatedAtMs' => (int) $raw['updatedAtMs']] : []);
    }

    /**
     * Belirli gunun araliklari (istisna onceliklidir). Saat yoksa bos: "acik" varsayilmaz.
     *
     * @return list<array{open:string,close:string}>
     */
    public static function intervalsOn(?array $hours, string $date): array
    {
        if ($hours === null) {
            return [];
        }
        foreach ($hours['exceptions'] as $e) {
            if ($e['date'] === $date) {
                return $e['intervals'];
            }
        }
        $day = (int) (new DateTimeImmutable($date, new DateTimeZone(self::TIMEZONE)))->format('N');
        foreach ($hours['week'] as $d) {
            if ((int) $d['day'] === $day) {
                return $d['intervals'];
            }
        }

        return [];
    }
}
