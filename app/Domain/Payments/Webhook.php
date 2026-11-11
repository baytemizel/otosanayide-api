<?php

namespace App\Domain\Payments;

use App\Domain\Ads\AdPayments;
use App\Domain\Brand\BrandPayments;
use App\Domain\Content\Digest;
use App\Domain\Content\Ids;
use App\Support\Tx;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * packages/server/payment-webhook.ts: kalici gelen kutusu (provider+merchant+eventId), eventId/govde ozeti bagi,
 * 30 sn kira, en fazla 20 deneme. Webhook govdesi odeme kaniti degildir: sonuc saglayicidan dogrudan sorgulanir.
 */
final class Webhook
{
    private static function receipt(?stdClass $r): ?array
    {
        return $r === null ? null : ['provider' => $r->provider, 'merchantId' => $r->merchant_id, 'eventDigest' => $r->event_digest, 'status' => $r->status, 'leaseId' => $r->lease_id, 'leaseUntilMs' => (int) $r->lease_until_ms, 'attempts' => (int) $r->attempts];
    }

    /**
     * @param  array{eventId:string,kind:string,orderId:string,paymentId:string,type:string}  $e
     * @return array{received:true,replayed:bool}
     */
    public static function apply(array $e, PaymentGateway $gateway): array
    {
        $e = PaymentGateway::eventSchema()->parse((object) $e);
        $scope = ['kind' => $e['kind'] === 'brand' ? 'brand_order' : 'ad_order', 'id' => $e['orderId']];
        $eventDigest = Digest::of($e);
        $id = Digest::of(['provider' => $gateway->name, 'merchantId' => $gateway->merchantId, 'eventId' => $e['eventId']]);
        $leaseId = Ids::uuid();
        $admitted = Tx::run(static function () use ($e, $scope, $eventDigest, $id, $leaseId, $gateway): bool {
            $old = self::receipt(F7::lock('payment_gateway_inbox', 'id', $id));
            if ($old !== null) {
                if ($old['eventDigest'] !== $eventDigest || $old['provider'] !== $gateway->name || $old['merchantId'] !== $gateway->merchantId) {
                    throw new RuntimeException('payment_event_conflict');
                }
                if ($old['status'] === 'applied') {
                    return false;
                }
            }
            $subjects = FinanceBarrier::assert(null, $scope);
            $identity = $e['kind'] === 'brand' ? F7::lock('brand_payment_bindings', 'reservation_id', $e['orderId']) : F7::lock('ad_orders', 'id', $e['orderId']);
            if ($identity === null || $identity->provider !== $gateway->name || $identity->merchant_id !== $gateway->merchantId) {
                throw new RuntimeException('payment_gateway_binding');
            }
            $now = F7::nowMs();
            if ($old !== null) {
                if ($old['leaseUntilMs'] > $now || $old['attempts'] >= 20) {
                    throw new RuntimeException('payment_event_busy');
                }
                DB::table('payment_gateway_inbox')->where('id', $id)->update(['status' => 'processing', 'lease_id' => $leaseId, 'lease_until_ms' => $now + 30000, 'attempts' => $old['attempts'] + 1]);
            } else {
                DB::table('payment_gateway_inbox')->insert(['id' => $id, 'schema' => 1, 'provider' => $gateway->name, 'merchant_id' => $gateway->merchantId, 'event_id' => $e['eventId'], 'event_digest' => $eventDigest, 'kind' => $e['kind'], 'order_id' => $e['orderId'], 'payment_id' => $e['paymentId'], 'subjects' => F7::json($subjects), 'status' => 'processing', 'lease_id' => $leaseId, 'lease_until_ms' => $now + 30000, 'attempts' => 1, 'result' => null]);
            }

            return true;
        });
        if (! $admitted) {
            return ['received' => true, 'replayed' => true];
        }
        try {
            $p = PaymentProvider::tracked(new PaymentProvider($gateway->name, $gateway->merchantId, static fn (string $paymentId): stdClass => $gateway->lookup($e['kind'], $e['orderId'], $paymentId)), $scope);
            $result = $e['kind'] === 'brand' ? BrandPayments::verify($e['orderId'], $p, $e['paymentId']) : AdPayments::settle($e['orderId'], $p, $e['paymentId']);
            FinanceBarrier::run(null, $scope, static function () use ($id, $eventDigest, $leaseId, $result): void {
                $v = self::receipt(F7::lock('payment_gateway_inbox', 'id', $id));
                if ($v === null || $v['eventDigest'] !== $eventDigest || $v['leaseId'] !== $leaseId || $v['status'] !== 'processing') {
                    throw new RuntimeException('payment_event_conflict');
                }
                DB::table('payment_gateway_inbox')->where('id', $id)->update(['status' => 'applied', 'result' => $result['status'], 'lease_until_ms' => 0]);
            });

            return ['received' => true, 'replayed' => false];
        } catch (Throwable $error) {
            try {
                FinanceBarrier::run(null, $scope, static function () use ($id, $leaseId): void {
                    $v = F7::lock('payment_gateway_inbox', 'id', $id);
                    if ($v?->lease_id === $leaseId && $v->status === 'processing') {
                        DB::table('payment_gateway_inbox')->where('id', $id)->update(['status' => 'review', 'lease_until_ms' => 0]);
                    }
                });
            } catch (Throwable) {
                // Ozel bekleyen olay bagimsiz incelemede kalir; bilinmeyen sonuc asla onaylanmaz.
            }
            throw $error;
        }
    }
}
