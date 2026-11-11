<?php

namespace App\Domain\Brand;

use App\Domain\Ads\AdRules;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** packages/server/brand-inventory.ts: yerlesim/gun kapasitesi, 15 dk tutma, birakma. Odenebilir degildir. */
final class BrandInventory
{
    /** recordSchema (gevsek: fazla alanlara izin verir) */
    private static function record(?array $raw): array
    {
        $v = Z::object([
            'campaignId' => F7::uuid(), 'campaignVersion' => Z::number()->int()->positive(), 'tariffVersion' => Z::number()->int()->positive(),
            'capacityVersion' => Z::number()->int()->positive(), 'placement' => Z::enum(BrandRules::PLACEMENTS),
            'dates' => Z::array(Z::string()->regex('/^\d{4}-\d{2}-\d{2}$/'))->min(1)->max(90), 'status' => Z::enum(['held', 'released', 'committed']),
            'expiresAtMs' => Z::number()->int()->nonnegative(), 'totalMinor' => Z::number()->int()->nonnegative(),
        ])->parse($raw === null ? null : (object) $raw);

        return $v;
    }

    public static function view(string $id, array $raw, int $now): array
    {
        $r = self::record($raw);
        $status = $r['status'] === 'committed' ? 'committed' : ($r['status'] === 'released' ? 'released' : ($r['expiresAtMs'] > $now ? 'held' : 'expired'));

        return ['id' => $id, 'campaignId' => $r['campaignId'], 'status' => $status, 'expiresAtMs' => $r['expiresAtMs']]
            + ($r['status'] === 'committed' ? ['committedUntilMs' => Z::number()->int()->positive()->parse($raw['committedUntilMs'] ?? null)] : [])
            + ['totalMinor' => $r['totalMinor'], 'currency' => 'TRY', 'campaignVersion' => $r['campaignVersion'], 'tariffVersion' => $r['tariffVersion'], 'capacityVersion' => $r['capacityVersion'], 'payable' => false];
    }

    public static function reserve(string $uid, mixed $raw, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $a = BrandRules::reservationInput()->parse($raw);

        return FinanceBarrier::run($uid, ['kind' => 'brand_campaign', 'id' => $a['campaignId']], static function () use ($uid, $a, $now): array {
            $old = BrandStore::reservation($a['requestId']);
            [, $anchor] = BrandStore::anchor($a['campaignId']);
            $row = BrandStore::campaign($a['campaignId']);
            if ($row === null) {
                throw new RuntimeException('campaign');
            }
            if ($old !== null) {
                $r = self::record($old);
                if ($r['campaignId'] !== $a['campaignId'] || $r['campaignVersion'] !== $a['campaignVersion'] || $r['tariffVersion'] !== $a['tariffVersion'] || $r['capacityVersion'] !== $a['capacityVersion']) {
                    throw new RuntimeException('replay');
                }

                return self::view($a['requestId'], $old, $now);
            }
            if ($row['status'] !== 'approved' || $row['version'] !== $a['campaignVersion']) {
                throw new RuntimeException('approval');
            }
            if (($anchor['expiresAtMs'] ?? 0) > $now) {
                throw new RuntimeException('held');
            }
            $v = $row['creative'];
            $t = BrandStore::tariff(BrandRules::tariffId(['placement' => $v['placement'], 'city' => $v['city'], 'category' => $v['category']]));
            $capacity = BrandRules::capacity()->parse(($c = BrandStore::capacity($v['placement'])) === null ? null : (object) $c);
            if (! $capacity['enabled'] || $capacity['version'] !== $a['capacityVersion']) {
                throw new RuntimeException('capacity');
            }
            $price = BrandRules::price($row, BrandRules::tariffView($t ?? []), ['campaignId' => $a['campaignId'], 'campaignVersion' => $a['campaignVersion'], 'tariffVersion' => $a['tariffVersion']], $now);
            $dates = BrandRules::dates($price['startDate'], $price['days']);
            $holds = [];
            foreach ($dates as $d) {
                [, $list] = BrandStore::day($v['placement'], $d);
                $holds[$d] = AdRules::allocate($list, $capacity['capacity'], $a['requestId'], $price['expiresAtMs'], $now);
            }
            foreach ($holds as $d => $list) {
                BrandStore::putDay($v['placement'], $d, $list);
            }
            $record = $price + ['placement' => $v['placement'], 'dates' => $dates, 'status' => 'held', 'actorId' => $uid, 'capacityVersion' => $capacity['version']];
            DB::table('brand_reservations')->insert([
                'id' => $a['requestId'], 'campaign_id' => $a['campaignId'], 'campaign_version' => $price['campaignVersion'], 'tariff_version' => $price['tariffVersion'],
                'capacity_version' => $capacity['version'], 'placement' => $v['placement'], 'market_placement' => $price['market']['placement'], 'market_city' => $price['market']['city'],
                'market_category' => $price['market']['category'], 'start_date' => $price['startDate'], 'end_date' => $price['endDate'], 'days' => $price['days'],
                'dates' => F7::json($dates), 'currency' => 'TRY', 'daily_minor' => $price['dailyMinor'], 'tax_basis_points' => $price['taxBasisPoints'],
                'subtotal_minor' => $price['subtotalMinor'], 'tax_minor' => $price['taxMinor'], 'total_minor' => $price['totalMinor'], 'status' => 'held', 'actor_id' => $uid,
                'created_at_ms' => $price['createdAtMs'], 'expires_at_ms' => $price['expiresAtMs'],
            ]);
            BrandStore::putAnchor($a['campaignId'], $a['requestId'], $price['expiresAtMs']);
            DB::table('brand_reservation_history')->insert(['reservation_id' => $a['requestId'], 'action' => 'reserved', 'actor_id' => $uid, 'at_ms' => $now]);

            return self::view($a['requestId'], $record, $now);
        });
    }

    /** releaseBrandHold: acik transaction icinde. */
    public static function release(string $campaignId, string $uid, int $now): void
    {
        BrandRules::campaignLookup()->parse((object) ['campaignId' => $campaignId]);
        FinanceBarrier::assert($uid, ['kind' => 'brand_campaign', 'id' => $campaignId]);
        [$exists, $anchor] = BrandStore::anchor($campaignId);
        if (! $exists) {
            return;
        }
        $id = F7::uuid()->parse($anchor['reservationId']);
        $raw = BrandStore::reservation($id);
        $r = self::record($raw);
        if ($r['campaignId'] !== $campaignId) {
            throw new RuntimeException('campaign');
        }
        FinanceBarrier::assert($uid, ['kind' => 'brand_reservation', 'id' => $id]);
        if ($r['status'] === 'released' || $r['status'] === 'committed') {
            return;
        }
        $next = [];
        foreach ($r['dates'] as $d) {
            [, $list] = BrandStore::day($r['placement'], $d);
            $next[$d] = array_values(array_filter(AdRules::holds($list, $now), static fn (array $h): bool => $h['reservationId'] !== $id));
        }
        foreach ($next as $d => $list) {
            BrandStore::putDay($r['placement'], $d, $list);
        }
        DB::table('brand_reservations')->where('id', $id)->update(['status' => 'released', 'released_at_ms' => $now]);
        BrandStore::putAnchor($campaignId, $id, 0);
        DB::table('brand_reservation_history')->insert(['reservation_id' => $id, 'action' => 'released', 'actor_id' => $uid, 'at_ms' => $now]);
    }

    public static function releaseTx(string $campaignId, string $uid): void
    {
        Tx::run(static fn () => self::release($campaignId, $uid, F7::nowMs()));
    }

    public static function read(string $campaignId, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        BrandRules::campaignLookup()->parse((object) ['campaignId' => $campaignId]);

        return Tx::run(static function () use ($campaignId, $now): array {
            $c = BrandStore::campaign($campaignId, false);
            if ($c === null) {
                throw new RuntimeException('campaign');
            }
            $placement = $c['creative']['placement'];
            [$exists, $anchor] = BrandStore::anchor($campaignId, false);
            $cap = BrandStore::capacity($placement, false);
            $reservation = null;
            if ($exists) {
                $id = F7::uuid()->parse($anchor['reservationId']);
                $r = BrandStore::reservation($id, false);
                if (($r['campaignId'] ?? null) !== $campaignId) {
                    throw new RuntimeException('campaign');
                }
                $reservation = self::view($id, $r, $now);
            }

            return ['capacity' => $cap !== null ? BrandRules::capacity()->parse((object) $cap) : null, 'reservation' => $reservation];
        });
    }
}
