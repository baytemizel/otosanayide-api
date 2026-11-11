<?php

namespace App\Domain\Brand;

use App\Domain\Payments\F7;
use App\Domain\Payments\FinanceBarrier;
use App\Domain\Payments\PaymentProvider;
use App\Exceptions\WriteConflict;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** packages/server/brand-payments.ts: saglayici baglama ve dogrulanmis odeme sonucu. Yayin/kapasite uzatmaz. */
final class BrandPayments
{
    private static function identity(PaymentProvider $p): array
    {
        Z::string()->regex('/^[a-z0-9_-]{1,40}$/')->parse($p->name);
        Z::string()->min(1)->max(200)->parse($p->merchantId);

        return ['provider' => $p->name, 'merchantId' => $p->merchantId];
    }

    public static function bind(string $id, PaymentProvider $p, ?int $now = null): void
    {
        $now ??= F7::nowMs();
        F7::uuid()->parse($id);
        $who = self::identity($p);
        FinanceBarrier::run(null, ['kind' => 'brand_order', 'id' => $id], static function () use ($id, $who, $now): void {
            $b = BrandStore::binding($id);
            $o = BrandStore::order($id);
            $r = BrandStore::reservation($id);
            if ($b !== null) {
                if ($b['provider'] !== $who['provider'] || $b['merchantId'] !== $who['merchantId']) {
                    throw new RuntimeException('provider_binding');
                }

                return;
            }
            $record = BrandRules::orderRecord($o ?? []);
            if ($record['reservationId'] !== $id || BrandOrders::inspect($id, $r, $record, $now) !== 'prepared') {
                throw new RuntimeException('reservation');
            }
            DB::table('brand_payment_bindings')->insert(['reservation_id' => $id, 'provider' => $who['provider'], 'merchant_id' => $who['merchantId'], 'created_at_ms' => $now]);
        });
    }

    /** brandPaymentResult */
    public static function result(): Z
    {
        return PaymentProvider::lookupSchema(18_000_000_000);
    }

    /** @return array{status:string,replayed:bool} */
    public static function verify(string $id, PaymentProvider $p, string $paymentId, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        F7::uuid()->parse($id);
        Z::string()->min(1)->max(200)->parse($paymentId);
        $who = self::identity($p);
        $matches = static fn (?array $v): bool => ($v['provider'] ?? null) === $who['provider'] && ($v['merchantId'] ?? null) === $who['merchantId'];
        $scope = ['kind' => 'brand_order', 'id' => $id];
        // Bir odeme referansi saglayiciya gonderilmeden once yapilandirma kontrol edilir, sonra atomik tekrar.
        $lookupBinding = FinanceBarrier::run(null, $scope, static function () use ($id, $matches): string {
            if (! $matches(BrandStore::binding($id))) {
                throw new RuntimeException('provider_binding');
            }

            return FinanceBarrier::lookupBinding('brand_order', $id);
        });
        $result = self::result()->parse($p->lookup($paymentId));
        if ($result['paymentId'] !== $paymentId || $result['orderId'] !== $id || $result['merchantId'] !== $who['merchantId']) {
            throw new RuntimeException('provider_binding');
        }
        $receiptId = F7::sha([$who['provider'], $who['merchantId'], $paymentId]);

        return FinanceBarrier::run(null, $scope, static function () use ($id, $who, $matches, $result, $receiptId, $lookupBinding, $paymentId, $now): array {
            if (FinanceBarrier::lookupBinding('brand_order', $id) !== $lookupBinding) {
                throw new WriteConflict('finance_write_conflict');
            }
            $b = BrandStore::binding($id);
            $o = BrandStore::order($id);
            $receipt = BrandStore::receipt($receiptId);
            $previous = BrandStore::paymentState($id);
            if (! $matches($b)) {
                throw new RuntimeException('provider_binding');
            }
            $record = BrandRules::orderRecord($o ?? []);
            if ($record['reservationId'] !== $id) {
                throw new RuntimeException('record_binding');
            }
            if ($receipt !== null) {
                if ($receipt['recordId'] !== $id) {
                    throw new RuntimeException('payment_reuse');
                }

                return ['status' => $receipt['outcome'], 'replayed' => true];
            }
            if ($result['status'] !== 'succeeded') {
                if (in_array($previous['status'] ?? null, ['verified', 'needs_review'], true)) {
                    return ['status' => $previous['status'], 'replayed' => true];
                }
                DB::table('brand_payment_states')->updateOrInsert(['reservation_id' => $id], ['status' => $result['status'], 'primary_receipt_id' => null, 'checked_at_ms' => $now]);

                return ['status' => $result['status'], 'replayed' => false];
            }
            $reason = '';
            $captured = $result['capturedAtMs'];
            if ($result['currency'] !== $record['currency'] || $result['amountMinor'] !== $record['totalMinor'] || $captured === null || $captured < $record['createdAtMs'] || $captured > $now) {
                $reason = 'amount_or_capture';
            } elseif (in_array($previous['status'] ?? null, ['verified', 'needs_review'], true)) {
                $reason = 'additional_payment';
            } else {
                $status = BrandOrders::inspect($id, BrandStore::reservation($id), $record, $now);
                if ($status !== 'prepared') {
                    $reason = $status;
                }
            }
            $outcome = $reason !== '' ? 'needs_review' : 'verified';
            DB::table('brand_payment_receipts')->insert(['id' => $receiptId, 'record_id' => $id, 'provider' => $who['provider'], 'merchant_id' => $who['merchantId'], 'payment_id' => $paymentId, 'currency' => $result['currency'], 'amount_minor' => $result['amountMinor'], 'captured_at_ms' => $captured, 'outcome' => $outcome, 'reason' => $reason, 'checked_at_ms' => $now]);
            DB::table('brand_payment_states')->updateOrInsert(['reservation_id' => $id], ['status' => $outcome, 'primary_receipt_id' => $previous['primaryReceiptId'] ?? $receiptId, 'checked_at_ms' => $now]);
            // Dogrulanmis makbuz bile bu asamada reklami yayinlamaz veya kapasiteyi uzatmaz.
            if ($reason !== '') {
                DB::table('brand_payment_cases')->insert(['id' => $receiptId, 'record_id' => $id, 'receipt_id' => $receiptId, 'reason' => $reason, 'status' => 'open', 'created_at_ms' => $now]);
            }
            DB::table('brand_order_history')->insert(['reservation_id' => $id, 'action' => 'payment_checked', 'receipt_id' => $receiptId, 'outcome' => $outcome, 'reason' => $reason, 'created_at_ms' => $now]);

            return ['status' => $outcome, 'replayed' => false];
        });
    }
}
