<?php

namespace App\Domain\Ads;

use App\Domain\Account\AccountFence;
use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Support\Tx;
use App\Support\ZodError;
use Illuminate\Support\Facades\DB;

/** packages/server/ad-inventory.ts: fiyat, stok (gun basina tutma), rezervasyon ve birakma sunucuda hesaplanir. */
final class AdInventory
{
    /**
     * @param  array{campaignId:string,businessId:string,campaignVersion:int}  $input
     * @return array{c:array<string,mixed>,t:array<string,mixed>,marketId:string,raw:array<string,mixed>}
     */
    private static function context(string $uid, array $input, int $now): array
    {
        $raw = AdStore::campaign($input['campaignId'], true);
        $c = $raw === null ? null : CampaignRules::view($input['campaignId'], $raw);
        ['b' => $b, 'm' => $m] = AdStore::business($uid, $input['businessId']);
        if ($c === null || $c['businessId'] !== $input['businessId'] || ! CampaignRules::owns($uid, $input['businessId'], $b, $m)) {
            throw new AdInventoryError('access');
        }
        AccountFence::assertWritable($uid);
        foreach ([$b['ownerUid'] ?? null, $raw['ownerUid'] ?? null] as $subject) {
            if ($subject !== null) {
                AccountFence::assertWritable($subject);
            }
        }
        if ($c['version'] !== $input['campaignVersion'] || $c['status'] !== 'approved' || ! CampaignRules::eligible($b, $c, $now)) {
            throw new AdInventoryError('campaign');
        }
        if (($c['approvedProfileVersion'] ?? null) !== ($b['profileVersion'] ?? 0)) {
            throw new AdInventoryError('profile');
        }
        $marketId = AdRules::marketId($c);
        $t = AdStore::tariff($marketId, true);
        $subset = [];
        foreach (AdRules::TARIFF_FIELDS as $k) {
            $subset[$k] = $t[$k] ?? null;
        }
        $parsed = AdRules::parseOk(AdRules::tariff(), (object) array_filter($subset, static fn (mixed $v): bool => $v !== null));
        if ($parsed === null) {
            throw new AdInventoryError('tariff');
        }

        return ['c' => $c, 't' => $parsed + ['version' => $t['version'] ?? null], 'marketId' => $marketId, 'raw' => $raw];
    }

    /** @param array{campaignId:string,businessId:string,campaignVersion:int} $input */
    public static function quote(string $uid, array $input, ?int $now = null): array
    {
        $now ??= F7::nowMs();

        return Tx::run(static function () use ($uid, $input, $now): array {
            ['c' => $c, 't' => $t] = self::context($uid, $input, $now);
            [, $anchor] = AdStore::anchor($input['campaignId']);
            $reservation = null;
            $id = $anchor['reservationId'] ?? null;
            if (is_string($id) && preg_match('/^[a-f0-9-]{36}$/iD', $id) === 1) {
                $r = AdStore::reservation($id);
                if ($r !== null && $r['userId'] === $uid && $r['campaignId'] === $input['campaignId'] && $r['businessId'] === $input['businessId']) {
                    $reservation = AdRules::reservationView($id, $r, $now);
                }
            }

            return AdRules::price($c, $t, $now) + ['reservation' => $reservation];
        });
    }

    /** @param array{campaignId:string,businessId:string,campaignVersion:int,tariffVersion:int,requestId:string} $input */
    public static function reserve(string $uid, array $input, ?int $now = null): array
    {
        $now ??= F7::nowMs();

        return Tx::run(static function () use ($uid, $input, $now): array {
            ['c' => $c, 't' => $t, 'marketId' => $marketId] = self::context($uid, $input, $now);
            if ($t['version'] !== $input['tariffVersion']) {
                throw new AdInventoryError('price_changed');
            }
            $price = AdRules::price($c, $t, $now);
            $existing = AdStore::reservation($input['requestId']);
            [, $anchor] = AdStore::anchor($input['campaignId']);
            if ($existing !== null) {
                $r = $existing;
                if ($r['userId'] !== $uid || $r['campaignId'] !== $input['campaignId'] || $r['businessId'] !== $input['businessId'] || $r['campaignVersion'] !== $input['campaignVersion'] || $r['tariffVersion'] !== $input['tariffVersion']) {
                    throw new AdInventoryError('replay');
                }

                return AdRules::reservationView($input['requestId'], $r, $now);
            }
            if (($anchor['expiresAtMs'] ?? 0) > $now) {
                throw new AdInventoryError('already_reserved');
            }
            $holds = [];
            foreach ($price['dates'] as $date) {
                [, $raw] = AdStore::day('ad_inventory_days', 'market_id', $marketId, $date);
                $holds[$date] = AdRules::allocate($raw, $t['capacity'], $input['requestId'], $price['expiresAtMs'], $now);
            }
            foreach ($holds as $date => $list) {
                AdStore::putDay('ad_inventory_days', 'market_id', $marketId, $date, $list);
            }
            $record = $price + ['userId' => $uid, 'campaignId' => $input['campaignId'], 'businessId' => $input['businessId'], 'marketId' => $marketId, 'status' => 'held', 'createdAtMs' => $now];
            DB::table('ad_reservations')->insert([
                'id' => $input['requestId'], 'user_id' => $uid, 'campaign_id' => $input['campaignId'], 'business_id' => $input['businessId'], 'market_id' => $marketId,
                'campaign_version' => $price['campaignVersion'], 'tariff_version' => $price['tariffVersion'], 'currency' => 'TRY', 'subtotal_minor' => $price['subtotalMinor'],
                'tax_minor' => $price['taxMinor'], 'total_minor' => $price['totalMinor'], 'dates' => F7::json($price['dates']), 'status' => 'held',
                'expires_at_ms' => $price['expiresAtMs'], 'created_at_ms' => $now,
            ]);
            AdStore::putAnchor($input['campaignId'], $input['requestId'], $price['expiresAtMs']);
            DB::table('ad_reservation_history')->insert(['reservation_id' => $input['requestId'], 'kind' => 'reserved', 'created_at_ms' => $now]);

            return AdRules::reservationView($input['requestId'], $record, $now);
        });
    }

    /** releaseCampaignHold: acik transaction icinde, diger yazimlardan once cagrilir. */
    public static function release(string $campaignId, int $now): void
    {
        [$exists, $anchor] = AdStore::anchor($campaignId);
        if (! $exists) {
            return;
        }
        $id = $anchor['reservationId'] ?? null;
        if (! is_string($id) || preg_match('/^[-a-f0-9]{36}$/iD', $id) !== 1) {
            throw new AdInventoryError('inventory');
        }
        $r = AdStore::reservation($id);
        if ($r === null) {
            throw new AdInventoryError('inventory');
        }
        FinanceBarrier::assert(null, ['kind' => 'ad_reservation', 'id' => $id]);
        if ($r['campaignId'] !== $campaignId || ! in_array($r['status'], ['held', 'released'], true)) {
            throw new AdInventoryError('inventory');
        }
        $dates = $r['dates'] ?? null;
        if (preg_match('/^\w{64}$/D', $r['marketId']) !== 1 || ! is_array($dates) || count($dates) > 30) {
            throw new AdInventoryError('inventory');
        }
        foreach ($dates as $d) {
            if (! is_string($d) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) !== 1) {
                throw new AdInventoryError('inventory');
            }
        }
        $next = [];
        foreach ($dates as $date) {
            [, $raw] = AdStore::day('ad_inventory_days', 'market_id', $r['marketId'], $date);
            $next[$date] = array_values(array_filter(AdRules::holds($raw, $now), static fn (array $h): bool => $h['reservationId'] !== $id));
        }
        foreach ($next as $date => $list) {
            AdStore::putDay('ad_inventory_days', 'market_id', $r['marketId'], $date, $list);
        }
        DB::table('ad_reservations')->where('id', $id)->update(['status' => 'released', 'released_at_ms' => $now]);
        AdStore::putAnchor($campaignId, $id, 0);
    }

    /** Kaynaktaki inventoryFailure: bilinen stok hatalari 409. */
    public static function known(\Throwable $e): bool
    {
        return $e instanceof AdInventoryError || ($e instanceof \RuntimeException && ! $e instanceof ZodError && in_array($e->getMessage(), ['capacity', 'tariff', 'schedule', 'inventory'], true));
    }
}
