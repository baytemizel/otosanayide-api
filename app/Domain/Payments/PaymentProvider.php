<?php

namespace App\Domain\Payments;

use App\Domain\Content\Digest;
use App\Domain\Content\ExternalCalls;
use App\Exceptions\WriteConflict;
use App\Support\Z;
use Closure;

/**
 * core/ad-payments.ts PaymentProvider: sunucuda yapilandirilmis adaptor saglayiciyi dogrudan sorgular.
 * Tarayici donusu veya webhook govdesinden asla kurulmaz.
 */
final class PaymentProvider
{
    /** @param Closure(string):mixed $lookup */
    public function __construct(public readonly string $name, public readonly string $merchantId, private readonly Closure $lookup) {}

    public function lookup(string $paymentId): mixed
    {
        return ($this->lookup)($paymentId);
    }

    public function check(): void
    {
        Z::string()->regex('/^[a-z0-9_-]{1,40}$/')->parse($this->name);
        Z::string()->min(1)->max(200)->parse($this->merchantId);
    }

    /**
     * trackedFinanceProvider: sorgu dis cagri kaydi icinde gider; kaynak/UID cagri oncesi tekrar okunur.
     *
     * @param  array{kind:'ad_order'|'brand_order'|'brand_cancellation',id:string}  $scope
     */
    public static function tracked(self $p, array $scope): self
    {
        $binding = Digest::of(['provider' => $p->name, 'merchantId' => $p->merchantId]);

        return new self($p->name, $p->merchantId, static fn (string $paymentId): mixed => ExternalCalls::call([
            'kind' => 'payments',
            'bindingDigest' => $binding,
            'resourceKey' => Digest::of(['scope' => $scope, 'provider' => $p->name, 'merchantId' => $p->merchantId, 'paymentId' => $paymentId]),
            'resolve' => static function () use ($p, $scope): array {
                $subjects = FinanceBarrier::assert(null, $scope);
                $identity = $scope['kind'] === 'ad_order' ? F7::lock('ad_orders', 'id', $scope['id']) : F7::lock('brand_payment_bindings', 'reservation_id', $scope['id']);
                if ($identity === null || $identity->provider !== $p->name || $identity->merchant_id !== $p->merchantId) {
                    throw new WriteConflict('finance_write_conflict');
                }

                return ['subjects' => $subjects, 'sourceDigest' => FinanceBarrier::lookupBinding($scope['kind'], $scope['id'])];
            },
        ], static fn (): mixed => $p->lookup($paymentId)));
    }

    /** paymentLookupSchema (amountMinor ust siniri marka icin 18e9). */
    public static function lookupSchema(int $maxAmount = 1_000_000_000): Z
    {
        return Z::strictObject([
            'paymentId' => Z::string()->min(1)->max(200),
            'merchantId' => Z::string()->min(1)->max(200),
            'orderId' => F7::uuid(),
            'status' => Z::enum(['pending', 'failed', 'succeeded']),
            'currency' => Z::string()->length(3),
            'amountMinor' => Z::number()->int()->nonnegative()->max($maxAmount),
            'capturedAtMs' => Z::number()->int()->nonnegative()->nullable(),
        ]);
    }
}
