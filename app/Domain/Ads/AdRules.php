<?php

namespace App\Domain\Ads;

use App\Domain\Payments\F7;
use App\Support\Z;
use App\Support\ZodError;
use RuntimeException;

/** packages/core/ad-inventory.ts, ad-metrics.ts, payment-cases.ts saf kurallari. */
final class AdRules
{
    public const TARIFF_FIELDS = ['placement', 'city', 'category', 'enabled', 'capacity', 'sevenDaysMinor', 'thirtyDaysMinor', 'taxBasisPoints'];

    /** adMarketSchema */
    public static function market(): Z
    {

        return Z::strictObject([
            'placement' => Z::enum(['search', 'category', 'home']),
            'city' => Z::string()->refine(static fn (string $v): bool => CampaignRules::isCity($v)),
            'category' => Z::string()->refine(static fn (string $v): bool => CampaignRules::isCategory($v)),
        ]);
    }

    private static function money(): Z
    {
        return Z::number()->int()->min(1)->max(100_000_000);
    }

    /** adTariffSchema */
    public static function tariff(): Z
    {
        return Z::strictObject([
            'placement' => Z::enum(['search', 'category', 'home']),
            'city' => Z::string()->refine(static fn (string $v): bool => CampaignRules::isCity($v)),
            'category' => Z::string()->refine(static fn (string $v): bool => CampaignRules::isCategory($v)),
            'enabled' => Z::boolean(),
            'capacity' => Z::number()->int()->min(1)->max(20),
            'sevenDaysMinor' => self::money(),
            'thirtyDaysMinor' => self::money(),
            'taxBasisPoints' => Z::number()->int()->min(0)->max(10000),
        ]);
    }

    public static function tariffUpdate(): Z
    {
        return Z::strictObject(['version' => Z::number()->int()->nonnegative(), 'value' => self::tariff(), 'reason' => Z::string()->trim()->min(10)->max(1000)]);
    }

    /** reservationInput */
    public static function reservation(): Z
    {
        return Z::strictObject([
            'campaignId' => F7::uuid(),
            'businessId' => Z::string()->regex('/^[A-Za-z0-9_-]{1,120}$/'),
            'campaignVersion' => Z::number()->int()->positive(),
            'tariffVersion' => Z::number()->int()->positive(),
            'requestId' => F7::uuid(),
        ]);
    }

    /** reservationInput.pick({campaignId,businessId,campaignVersion}) = adFinanceQuery */
    public static function financeQuery(): Z
    {
        return Z::strictObject([
            'campaignId' => F7::uuid(),
            'businessId' => Z::string()->regex('/^[A-Za-z0-9_-]{1,120}$/'),
            'campaignVersion' => Z::number()->int()->positive(),
        ]);
    }

    /** adMarketId: sha256(JSON.stringify([placement,city,category])) */
    public static function marketId(array $m): string
    {
        self::market()->parse((object) ['placement' => $m['placement'] ?? null, 'city' => $m['city'] ?? null, 'category' => $m['category'] ?? null]);

        return F7::sha([$m['placement'], $m['city'], $m['category']]);
    }

    /**
     * adPrice
     *
     * @param  array<string,mixed>  $c  CampaignView
     * @param  array<string,mixed>  $t  tarife + version
     * @return array<string,mixed>
     */
    public static function price(array $c, array $t, int $now): array
    {
        $subset = [];
        foreach (self::TARIFF_FIELDS as $k) {
            if (array_key_exists($k, $t)) {
                $subset[$k] = $t[$k];
            }
        }
        self::tariff()->parse((object) $subset);
        $version = $t['version'] ?? null;
        if ($t['enabled'] !== true || $c['placement'] !== $t['placement'] || $c['city'] !== $t['city'] || $c['category'] !== $t['category'] || ! F7::safeInt($version) || $version < 1) {
            throw new RuntimeException('tariff');
        }
        $start = F7::trStart($c['startDate']);
        if ($start === null || $start <= $now || $start > $now + 180 * F7::DAY) {
            throw new RuntimeException('schedule');
        }
        $subtotal = $c['days'] === 7 ? $t['sevenDaysMinor'] : $t['thirtyDaysMinor'];
        $tax = F7::round($subtotal * $t['taxBasisPoints'] / 10000);

        return ['currency' => 'TRY', 'subtotalMinor' => $subtotal, 'taxMinor' => $tax, 'totalMinor' => $subtotal + $tax, 'tariffVersion' => $version, 'campaignVersion' => $c['version'], 'dates' => CampaignRules::dates($c['startDate'], $c['days']), 'expiresAtMs' => min($now + 15 * 60000, $start)];
    }

    /**
     * availableHolds
     *
     * @return list<array{reservationId:string,expiresAtMs:int}>
     */
    public static function holds(mixed $raw, int $now): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > 20) {
            throw new RuntimeException('inventory');
        }
        $item = Z::strictObject(['reservationId' => F7::uuid(), 'expiresAtMs' => Z::number()->int()->positive()]);
        $out = [];
        foreach ($raw as $v) {
            $h = $item->parse(is_array($v) ? (object) $v : $v);
            if ($h['expiresAtMs'] > $now) {
                $out[] = $h;
            }
        }

        return $out;
    }

    /** @return list<array{reservationId:string,expiresAtMs:int}> allocateDay */
    public static function allocate(mixed $raw, int $capacity, string $reservationId, int $expiresAtMs, int $now): array
    {
        $holds = self::holds($raw, $now);
        foreach ($holds as $h) {
            if ($h['reservationId'] === $reservationId) {
                return $holds;
            }
        }
        if (count($holds) >= $capacity) {
            throw new RuntimeException('capacity');
        }
        $holds[] = ['reservationId' => $reservationId, 'expiresAtMs' => $expiresAtMs];

        return $holds;
    }

    /** metricEvent */
    public static function metricEvent(): Z
    {
        return Z::strictObject(['token' => Z::string()->min(40)->max(1800)->regex('/^[A-Za-z0-9_-]+$/'), 'kind' => Z::enum(['view', 'click'])]);
    }

    public static function metricQuery(): Z
    {
        return Z::strictObject(['campaignId' => F7::uuid()]);
    }

    /** @return list<string> metricDates */
    public static function metricDates(int $now): array
    {
        $end = F7::trStart(F7::isoDate($now + 10800000));
        $out = [];
        for ($i = 0; $i < 30; $i++) {
            $out[] = F7::isoDate($end + 10800000 - (29 - $i) * F7::DAY);
        }

        return $out;
    }

    /**
     * metricSummary
     *
     * @param  list<array{date:string,views:int,clicks:int}>  $rows
     * @return array{rows:list<array{date:string,views:int,clicks:int}>,views:int,clicks:int,ctr:int|float|null}
     */
    public static function summary(array $rows): array
    {
        $views = array_sum(array_column($rows, 'views'));
        $clicks = array_sum(array_column($rows, 'clicks'));

        return ['rows' => $rows, 'views' => $views, 'clicks' => $clicks, 'ctr' => $views ? F7::num(F7::round($clicks / $views * 10000) / 100) : null];
    }

    public static function reservationView(string $id, array $r, int $now): array
    {
        $status = $r['status'] === 'paid' ? 'paid' : ($r['status'] === 'released' ? 'released' : ((int) $r['expiresAtMs'] > $now ? 'held' : 'expired'));

        return ['id' => $id, 'status' => $status, 'expiresAtMs' => $r['expiresAtMs'], 'currency' => $r['currency'], 'subtotalMinor' => $r['subtotalMinor'], 'taxMinor' => $r['taxMinor'], 'totalMinor' => $r['totalMinor'], 'tariffVersion' => $r['tariffVersion'], 'campaignVersion' => $r['campaignVersion']];
    }

    public static function parseOk(Z $schema, mixed $v): ?array
    {
        try {
            return $schema->parse($v);
        } catch (ZodError) {
            return null;
        }
    }
}
