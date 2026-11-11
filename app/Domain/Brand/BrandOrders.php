<?php

namespace App\Domain\Brand;

use App\Domain\Ads\AdRules;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Support\Tx;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** packages/server/brand-orders.ts: yalniz hazirlanmis finans kaydi; saglayici/checkout/makbuz yok. */
final class BrandOrders
{
    /**
     * inspectBrandReservation: transaction icinde.
     *
     * @return 'prepared'|'expired'|'cancelled'|'invalidated'
     */
    public static function inspect(string $id, ?array $r, ?array $o, int $now): string
    {
        if ($now < 0) {
            return 'invalidated';
        }
        if ($o !== null && $now >= $o['expiresAtMs']) {
            return 'expired';
        }
        if ($r === null) {
            return 'invalidated';
        }
        if ($r['status'] === 'released') {
            return 'cancelled';
        }
        if ($r['status'] !== 'held') {
            return 'invalidated';
        }
        if (! is_int($r['expiresAtMs'] ?? null) || $r['expiresAtMs'] <= $now) {
            return 'expired';
        }
        if (! F7::isUuid($r['campaignId'] ?? null)) {
            return 'invalidated';
        }
        $c = BrandStore::campaign($r['campaignId']);
        [, $anchor] = BrandStore::anchor($r['campaignId']);
        if ($c === null || $c['status'] !== 'approved' || $c['version'] !== $r['campaignVersion'] || $anchor === null || $anchor['reservationId'] !== $id || $anchor['expiresAtMs'] !== $r['expiresAtMs']) {
            return 'invalidated';
        }
        try {
            $key = BrandRules::tariffId(['placement' => $c['creative']['placement'], 'city' => $c['creative']['city'], 'category' => $c['creative']['category']]);
        } catch (Throwable) {
            return 'invalidated';
        }
        $ts = BrandStore::tariff($key);
        $cap = BrandStore::capacity($c['creative']['placement']);
        try {
            $capacity = BrandRules::capacity()->parse($cap === null ? null : (object) $cap);
            if (! $capacity['enabled'] || $capacity['version'] !== $r['capacityVersion']) {
                return 'invalidated';
            }
            $price = BrandRules::price($c, BrandRules::tariffView($ts ?? []), ['campaignId' => $r['campaignId'], 'campaignVersion' => $r['campaignVersion'], 'tariffVersion' => $r['tariffVersion']], $now);
            $dates = BrandRules::dates($price['startDate'], $price['days']);
            if ($r['currency'] !== 'TRY' || $r['placement'] !== $c['creative']['placement'] || $r['dates'] !== $dates || $r['subtotalMinor'] !== $price['subtotalMinor'] || $r['taxMinor'] !== $price['taxMinor'] || $r['totalMinor'] !== $price['totalMinor']) {
                return 'invalidated';
            }
            if (! F7::safeInt($r['createdAtMs'] ?? null) || $r['createdAtMs'] < 0 || $r['createdAtMs'] > $now || $r['expiresAtMs'] > $r['createdAtMs'] + 900000 || $r['expiresAtMs'] > F7::trStart($price['startDate'])) {
                return 'invalidated';
            }
            if ($o !== null) {
                foreach (['campaignId', 'campaignVersion', 'tariffVersion', 'capacityVersion', 'currency', 'subtotalMinor', 'taxMinor', 'totalMinor', 'expiresAtMs'] as $k) {
                    if ($o[$k] !== ($r[$k] ?? null)) {
                        return 'invalidated';
                    }
                }
            }
        } catch (Throwable) {
            return 'invalidated';
        }
        try {
            foreach ($dates as $date) {
                [, $list] = BrandStore::day($c['creative']['placement'], $date);
                $found = false;
                foreach (AdRules::holds($list, $now) as $h) {
                    $found = $found || ($h['reservationId'] === $id && $h['expiresAtMs'] === $r['expiresAtMs']);
                }
                if (! $found) {
                    return 'invalidated';
                }
            }
        } catch (Throwable) {
            return 'invalidated';
        }

        return 'prepared';
    }

    public static function create(string $uid, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $id = BrandRules::orderInput()->parse($raw)['reservationId'];

        return FinanceBarrier::run($uid, ['kind' => 'brand_reservation', 'id' => $id], static function () use ($uid, $id, $now): array {
            $old = BrandStore::order($id);
            $r = BrandStore::reservation($id);
            $o = $old !== null ? BrandRules::orderRecord($old) : null;
            if ($o !== null && $o['reservationId'] !== $id) {
                throw new RuntimeException('binding');
            }
            $status = self::inspect($id, $r, $o, $now);
            if ($o !== null) {
                return BrandRules::orderView($o, $status);
            }
            if ($status !== 'prepared') {
                throw new RuntimeException('reservation');
            }
            $record = BrandRules::orderRecord(['reservationId' => $id, 'campaignId' => $r['campaignId'], 'campaignVersion' => $r['campaignVersion'], 'tariffVersion' => $r['tariffVersion'], 'capacityVersion' => $r['capacityVersion'], 'currency' => $r['currency'], 'subtotalMinor' => $r['subtotalMinor'], 'taxMinor' => $r['taxMinor'], 'totalMinor' => $r['totalMinor'], 'createdAtMs' => $now, 'expiresAtMs' => $r['expiresAtMs'], 'status' => 'prepared']);
            DB::table('brand_orders')->insert(['reservation_id' => $id, 'campaign_id' => $record['campaignId'], 'campaign_version' => $record['campaignVersion'], 'tariff_version' => $record['tariffVersion'], 'capacity_version' => $record['capacityVersion'], 'currency' => 'TRY', 'subtotal_minor' => $record['subtotalMinor'], 'tax_minor' => $record['taxMinor'], 'total_minor' => $record['totalMinor'], 'created_at_ms' => $now, 'expires_at_ms' => $record['expiresAtMs'], 'status' => 'prepared']);
            DB::table('brand_order_history')->insert(['reservation_id' => $id, 'action' => 'prepared', 'actor_id' => $uid, 'created_at_ms' => $now]);

            return BrandRules::orderView($record, 'prepared');
        });
    }

    public static function read(mixed $raw, ?int $now = null): ?array
    {
        $now ??= F7::nowMs();
        $id = BrandRules::orderInput()->parse($raw)['reservationId'];

        return Tx::run(static function () use ($id, $now): ?array {
            $os = BrandStore::order($id);
            $r = BrandStore::reservation($id);
            if ($os === null) {
                return null;
            }
            $o = BrandRules::orderRecord($os);
            if ($o['reservationId'] !== $id) {
                throw new RuntimeException('binding');
            }

            return BrandRules::orderView($o, self::inspect($id, $r, $o, $now));
        });
    }
}
