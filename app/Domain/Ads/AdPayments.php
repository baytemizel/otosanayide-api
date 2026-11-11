<?php

namespace App\Domain\Ads;

use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Domain\Payments\PaymentProvider;
use App\Exceptions\WriteConflict;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** packages/server/ad-payments.ts: rezervasyon basina tek degismez siparis ve dahili odeme sonucu islemesi. */
final class AdPayments
{
    /** createAdOrder: checkout baslatmaz, kart verisi tasimaz. */
    public static function createOrder(string $uid, string $reservationId, PaymentProvider $provider, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        F7::uuid()->parse($reservationId);
        $provider->check();

        return FinanceBarrier::run($uid, ['kind' => 'ad_reservation', 'id' => $reservationId], static function () use ($uid, $reservationId, $provider, $now): array {
            $old = AdStore::order($reservationId);
            $r = AdStore::reservation($reservationId);
            if ($r === null || $r['userId'] !== $uid) {
                throw new RuntimeException('access');
            }
            $raw = AdStore::campaign(F7::uuid()->parse($r['campaignId']), true);
            ['b' => $b, 'm' => $m] = AdStore::business($uid, $r['businessId']);
            [, $anchor] = AdStore::anchor($r['campaignId']);
            $c = $raw === null ? null : CampaignRules::view($r['campaignId'], $raw);
            if (! CampaignRules::owns($uid, $r['businessId'], $b, $m)) {
                throw new RuntimeException('access');
            }
            if ($old !== null) {
                if ($old['userId'] !== $uid || $old['provider'] !== $provider->name || $old['merchantId'] !== $provider->merchantId) {
                    throw new RuntimeException('order_conflict');
                }

                return ['id' => $reservationId, 'status' => $old['status'], 'totalMinor' => $old['totalMinor'], 'currency' => $old['currency']];
            }
            if ($c === null || $c['status'] !== 'approved' || $c['version'] !== $r['campaignVersion'] || ($c['approvedProfileVersion'] ?? null) !== ($b['profileVersion'] ?? 0) || ! CampaignRules::eligible($b, $c, $now) || $r['status'] !== 'held' || $r['expiresAtMs'] <= $now || ($anchor['reservationId'] ?? null) !== $reservationId) {
                throw new RuntimeException('reservation');
            }
            $dates = CampaignRules::dates($c['startDate'], $c['days']);
            if ($dates !== $r['dates'] || preg_match('/^[a-f0-9]{64}$/D', $r['marketId']) !== 1 || $r['currency'] !== 'TRY' || $r['totalMinor'] <= 0 || $r['totalMinor'] !== $r['subtotalMinor'] + $r['taxMinor']) {
                throw new RuntimeException('order');
            }
            foreach ($dates as $date) {
                [, $holds] = AdStore::day('ad_inventory_days', 'market_id', $r['marketId'], $date);
                if (! self::holding(AdRules::holds($holds, $now), $reservationId)) {
                    throw new RuntimeException('capacity');
                }
            }
            DB::table('ad_orders')->insert([
                'id' => $reservationId, 'user_id' => $uid, 'reservation_id' => $reservationId, 'campaign_id' => $r['campaignId'], 'business_id' => $r['businessId'],
                'campaign_version' => $c['version'], 'provider' => $provider->name, 'merchant_id' => $provider->merchantId, 'currency' => $r['currency'],
                'subtotal_minor' => $r['subtotalMinor'], 'tax_minor' => $r['taxMinor'], 'total_minor' => $r['totalMinor'], 'status' => 'pending', 'created_at_ms' => $now,
            ]);

            return ['id' => $reservationId, 'status' => 'pending', 'totalMinor' => $r['totalMinor'], 'currency' => $r['currency']];
        });
    }

    /** @param list<array{reservationId:string,expiresAtMs:int}> $holds */
    private static function holding(array $holds, string $id): bool
    {
        foreach ($holds as $h) {
            if ($h['reservationId'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * settleAdPayment: saglayiciya once kaynak kontrolu, sonra sorgu, sonra atomik tekrar kontrol.
     * Basarisiz/bekleyen bildirim, sonuclanmis siparisi asla geri cekmez.
     *
     * @return array{status:string,replayed:bool}
     */
    public static function settle(string $orderId, PaymentProvider $provider, string $paymentId, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        F7::uuid()->parse($orderId);
        $provider->check();
        Z::string()->min(1)->max(200)->parse($paymentId);
        $scope = ['kind' => 'ad_order', 'id' => $orderId];
        $binding = FinanceBarrier::run(null, $scope, static function () use ($orderId, $provider): string {
            $o = AdStore::order($orderId);
            if (($o['provider'] ?? null) !== $provider->name || ($o['merchantId'] ?? null) !== $provider->merchantId) {
                throw new RuntimeException('provider_binding');
            }

            return FinanceBarrier::lookupBinding('ad_order', $orderId);
        });
        $result = PaymentProvider::lookupSchema()->parse($provider->lookup($paymentId));
        if ($result['paymentId'] !== $paymentId || $result['merchantId'] !== $provider->merchantId || $result['orderId'] !== $orderId) {
            throw new RuntimeException('provider_binding');
        }
        $receiptId = F7::sha([$provider->name, $provider->merchantId, $paymentId]);

        return FinanceBarrier::run(null, $scope, static function () use ($orderId, $provider, $paymentId, $result, $receiptId, $binding, $now): array {
            if (FinanceBarrier::lookupBinding('ad_order', $orderId) !== $binding) {
                throw new WriteConflict('finance_write_conflict');
            }
            $o = AdStore::order($orderId);
            $receipt = F7::lock('ad_payment_receipts', 'id', $receiptId);
            if ($o === null || $o['provider'] !== $provider->name || $o['merchantId'] !== $provider->merchantId) {
                throw new RuntimeException('provider_binding');
            }
            if ($receipt !== null) {
                if ($receipt->order_id !== $orderId) {
                    throw new RuntimeException('payment_reuse');
                }

                return ['status' => $receipt->outcome, 'replayed' => true];
            }
            if ($result['status'] !== 'succeeded') {
                if ($o['status'] === 'paid' || $o['status'] === 'needs_review') {
                    return ['status' => $o['status'], 'replayed' => true];
                }
                DB::table('ad_orders')->where('id', $orderId)->update(['status' => $result['status'], 'checked_at_ms' => $now]);

                return ['status' => $result['status'], 'replayed' => false];
            }
            $r = AdStore::reservation($o['reservationId']);
            $raw = AdStore::campaign($o['campaignId'], true);
            ['b' => $b, 'm' => $m] = AdStore::business($o['userId'], $o['businessId']);
            [, $anchor] = AdStore::anchor($o['campaignId']);
            $c = $raw === null ? null : CampaignRules::view($o['campaignId'], $raw);
            $reason = '';
            $captured = $result['capturedAtMs'];
            if ($result['currency'] !== $o['currency'] || $result['amountMinor'] !== $o['totalMinor'] || $captured === null || $captured > $now || $captured < $o['createdAtMs']) {
                $reason = 'amount_or_capture';
            } elseif ($o['status'] === 'paid' || $o['status'] === 'needs_review') {
                $reason = 'additional_payment';
            } elseif ($r === null || $r['status'] !== 'held' || $r['expiresAtMs'] <= $now || ($anchor['reservationId'] ?? null) !== $o['reservationId']) {
                $reason = 'expired_or_released';
            } elseif ($c === null || $c['status'] !== 'approved' || $c['version'] !== $o['campaignVersion'] || ($c['approvedProfileVersion'] ?? null) !== ($b['profileVersion'] ?? 0) || ! CampaignRules::owns($o['userId'], $o['businessId'], $b, $m) || ! CampaignRules::eligible($b, $c, $now)) {
                $reason = 'eligibility_changed';
            }
            $dates = $c !== null ? CampaignRules::dates($c['startDate'], $c['days']) : [];
            if ($reason === '' && ($r['dates'] !== $dates || preg_match('/^[a-f0-9]{64}$/D', $r['marketId']) !== 1)) {
                $reason = 'inventory';
            }
            $holds = [];
            if ($reason === '') {
                foreach ($dates as $date) {
                    [, $raw2] = AdStore::day('ad_inventory_days', 'market_id', $r['marketId'], $date);
                    $holds[$date] = AdRules::holds($raw2, $now);
                    if (! self::holding($holds[$date], $o['reservationId'])) {
                        $reason = 'capacity_lost';
                    }
                }
            }
            $outcome = $reason !== '' ? 'needs_review' : 'paid';
            DB::table('ad_payment_receipts')->insert(['id' => $receiptId, 'order_id' => $orderId, 'provider' => $provider->name, 'payment_id' => $paymentId, 'currency' => $result['currency'], 'amount_minor' => $result['amountMinor'], 'outcome' => $outcome, 'reason' => $reason, 'captured_at_ms' => $captured, 'checked_at_ms' => $now]);
            if ($reason !== '') {
                if ($o['status'] !== 'paid' && $o['status'] !== 'needs_review') {
                    DB::table('ad_orders')->where('id', $orderId)->update(['status' => 'needs_review', 'reason' => $reason, 'checked_at_ms' => $now]);
                }
                DB::table('ad_payment_cases')->insert(['id' => $receiptId, 'order_id' => $orderId, 'receipt_id' => $receiptId, 'reason' => $reason, 'status' => 'open', 'version' => 0, 'created_at_ms' => $now]);

                return ['status' => $outcome, 'replayed' => false];
            }
            $endAt = F7::trStart(end($dates)) + F7::DAY;
            foreach ($holds as $date => $list) {
                AdStore::putDay('ad_inventory_days', 'market_id', $r['marketId'], $date, array_map(static fn (array $h): array => $h['reservationId'] === $o['reservationId'] ? ['reservationId' => $h['reservationId'], 'expiresAtMs' => $endAt] : $h, $list));
            }
            DB::table('ad_reservations')->where('id', $o['reservationId'])->update(['status' => 'paid', 'expires_at_ms' => $endAt, 'order_id' => $orderId]);
            AdStore::putAnchor($o['campaignId'], $o['reservationId'], $endAt);
            DB::table('ad_campaigns')->where('id', $o['campaignId'])->update(['status' => 'scheduled', 'version' => $c['version'] + 1, 'updated_at_ms' => $now]);
            DB::table('ad_orders')->where('id', $orderId)->update(['status' => 'paid', 'receipt_id' => $receiptId, 'paid_at_ms' => $now]);

            return ['status' => $outcome, 'replayed' => false];
        });
    }
}
