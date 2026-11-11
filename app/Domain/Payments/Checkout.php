<?php

namespace App\Domain\Payments;

use App\Domain\Ads\AdPayments;
use App\Domain\Ads\AdRules;
use App\Domain\Ads\AdStore;
use App\Domain\Ads\CampaignRules;
use App\Domain\Brand\BrandOrders;
use App\Domain\Brand\BrandPayments;
use App\Domain\Brand\BrandRules;
use App\Domain\Brand\BrandStore;
use App\Domain\Content\Digest;
use App\Domain\Content\ExternalCalls;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * packages/server/hosted-checkout.ts + core/hosted-checkout.ts.
 * Donus URL'si odeme kaniti degildir. Kayip checkout sonucu otomatik yeniden olusturulmaz;
 * belirsiz dis sonuc yeniden gonderilmez; hazir oturum yalniz suresi dolmamissa tekrar verilir.
 */
final class Checkout
{
    public static function command(): Z
    {
        return Z::strictObject(['kind' => Z::enum(['business', 'brand']), 'reservationId' => F7::uuid()]);
    }

    /** checkoutSession */
    public static function sessionSchema(): Z
    {
        return Z::strictObject([
            'sessionId' => Z::string()->regex('/^[A-Za-z0-9_-]{1,200}$/'),
            'url' => Z::string()->max(4096)->refine(static fn (string $v): bool => self::httpsUrl($v) !== null),
            'expiresAtMs' => Z::number()->int()->positive()->max(F7::SAFE),
        ]);
    }

    /** checkoutOrder */
    public static function orderSchema(): Z
    {
        return Z::strictObject([
            'kind' => Z::enum(['business', 'brand']), 'orderId' => F7::uuid(), 'currency' => Z::literal('TRY'),
            'amountMinor' => Z::number()->int()->positive()->max(18_000_000_000), 'expiresAtMs' => Z::number()->int()->positive()->max(F7::SAFE),
            'idempotencyKey' => F7::hash(),
        ]);
    }

    /** new URL(v): https, kullanici/parola/port/hash yok; origin doner. */
    public static function httpsUrl(string $v): ?string
    {
        if (preg_match('/\s/', $v) === 1 || str_contains($v, '#')) {
            return null;
        }
        $u = parse_url($v);
        if (! is_array($u) || strtolower($u['scheme'] ?? '') !== 'https' || ! isset($u['host']) || $u['host'] === '' || isset($u['user']) || isset($u['pass']) || isset($u['port'])) {
            return null;
        }

        return 'https://'.strtolower($u['host']);
    }

    /**
     * validateCheckoutRedirect
     *
     * @param  list<string>  $origins
     */
    public static function validateRedirect(mixed $raw, array $origins, int $now, array $order): array
    {
        $session = self::sessionSchema()->parse($raw);
        $origin = self::httpsUrl($session['url']);
        if ($origin === null || ! in_array($origin, $origins, true) || $session['expiresAtMs'] <= $now || $session['expiresAtMs'] > $order['expiresAtMs']) {
            throw new RuntimeException('checkout_redirect');
        }

        return $session;
    }

    private static function key(string $kind, string $id): string
    {
        return $kind.'_'.$id;
    }

    /** @return array<string,mixed>|null checkoutRecord */
    public static function record(string $kind, string $id): ?array
    {
        $r = F7::lock('payment_checkout_sessions', 'id', self::key($kind, $id));
        if ($r === null) {
            return null;
        }

        return ['schema' => (int) $r->schema, 'kind' => $r->kind, 'orderId' => $r->order_id, 'userId' => $r->user_id, 'subjects' => F7::list($r->subjects), 'sourceDigest' => $r->source_digest,
            'provider' => $r->provider, 'merchantId' => $r->merchant_id, 'idempotencyKey' => $r->idempotency_key, 'status' => $r->status,
            'session' => $r->session_id === null ? null : ['sessionId' => $r->session_id, 'url' => $r->session_url, 'expiresAtMs' => (int) $r->session_expires_at_ms],
            'createdAtMs' => (int) $r->created_at_ms, 'updatedAtMs' => (int) $r->updated_at_ms, 'version' => (int) $r->version];
    }

    private static function update(string $kind, string $id, array $patch): void
    {
        DB::table('payment_checkout_sessions')->where('id', self::key($kind, $id))->update($patch);
    }

    /**
     * checkoutContext: transaction icinde; fatura kimligi ve rezervasyon ozeti.
     *
     * @param  array{uid:string,brandFinance:bool}  $actor
     * @return array{subjects:list<string>,sourceDigest:string,currency:string,amountMinor:int,expiresAtMs:int}
     */
    public static function context(array $actor, string $kind, string $id, int $now): array
    {
        $brand = $kind === 'brand';
        $scope = ['kind' => $brand ? 'brand_order' : 'ad_order', 'id' => $id];
        if ($brand && ! $actor['brandFinance']) {
            throw new RuntimeException('checkout_access');
        }
        $subjects = FinanceBarrier::assert($actor['uid'], $scope);
        $o = $brand ? BrandStore::order($id) : AdStore::order($id);
        $r = $brand ? BrandStore::reservation($id) : AdStore::reservation($id);
        if ($brand) {
            $state = BrandStore::paymentState($id);
            if (in_array($state['status'] ?? null, ['verified', 'needs_review'], true)) {
                throw new RuntimeException('checkout_paid');
            }
            $b = BrandRules::orderRecord($o);
            if (BrandOrders::inspect($id, $r, $b, $now) !== 'prepared') {
                throw new RuntimeException('checkout_reservation');
            }
        } else {
            if ($r === null || $o['userId'] !== $actor['uid'] || ! in_array($o['status'], ['pending', 'failed'], true) || $r['status'] !== 'held' || $r['expiresAtMs'] <= $now || $o['currency'] !== $r['currency'] || $o['totalMinor'] !== $r['totalMinor'] || $o['totalMinor'] !== $o['subtotalMinor'] + $o['taxMinor']) {
                throw new RuntimeException('checkout_reservation');
            }
            $raw = AdStore::campaign($o['campaignId'], true);
            ['b' => $bv, 'm' => $m] = AdStore::business($actor['uid'], $o['businessId']);
            [, $anchor] = AdStore::anchor($o['campaignId']);
            $cv = $raw === null ? null : CampaignRules::view($o['campaignId'], $raw);
            if ($cv === null || $cv['status'] !== 'approved' || $cv['version'] !== $o['campaignVersion'] || ($cv['approvedProfileVersion'] ?? null) !== ($bv['profileVersion'] ?? 0) || ! CampaignRules::eligible($bv, $cv, $now) || ! CampaignRules::owns($actor['uid'], $o['businessId'], $bv, $m) || ($anchor['reservationId'] ?? null) !== $id || $r['campaignVersion'] !== $o['campaignVersion']) {
                throw new RuntimeException('checkout_reservation');
            }
            $dates = CampaignRules::dates($cv['startDate'], $cv['days']);
            if ($dates !== $r['dates'] || $r['marketId'] !== AdRules::marketId($cv)) {
                throw new RuntimeException('checkout_inventory');
            }
            foreach ($dates as $date) {
                [, $holds] = AdStore::day('ad_inventory_days', 'market_id', $r['marketId'], $date);
                $n = count(array_filter(AdRules::holds($holds, $now), static fn (array $h): bool => $h['reservationId'] === $id && $h['expiresAtMs'] === $r['expiresAtMs']));
                if ($n !== 1) {
                    throw new RuntimeException('checkout_inventory');
                }
            }
        }

        return ['subjects' => $subjects, 'sourceDigest' => Digest::of(['billing' => FinanceBarrier::lookupBinding($scope['kind'], $id), 'reservation' => ['expiresAtMs' => $r['expiresAtMs'], 'dates' => $r['dates'], 'marketId' => $r['marketId'] ?? null, 'status' => $r['status']]]),
            'currency' => $o['currency'], 'amountMinor' => $o['totalMinor'], 'expiresAtMs' => min($o['expiresAtMs'] ?? $r['expiresAtMs'], $r['expiresAtMs'])];
    }

    /**
     * prepareHostedCheckout
     *
     * @param  array{uid:string,brandFinance:bool}  $actor
     */
    public static function prepare(array $actor, mixed $raw, PaymentGateway $gateway): array
    {
        Z::string()->regex('/^[A-Za-z0-9_-]{1,128}$/')->parse($actor['uid']);
        $c = self::command()->parse($raw);
        $kind = $c['kind'];
        $id = $c['reservationId'];
        $scope = ['kind' => $kind === 'brand' ? 'brand_order' : 'ad_order', 'id' => $id];
        $p = new PaymentProvider($gateway->name, $gateway->merchantId, static fn (string $paymentId): stdClass => $gateway->lookup($kind, $id, $paymentId));
        if ($kind === 'brand') {
            if (! $actor['brandFinance']) {
                throw new RuntimeException('checkout_access');
            }
            BrandOrders::create($actor['uid'], (object) ['reservationId' => $id]);
            BrandPayments::bind($id, $p);
        } else {
            AdPayments::createOrder($actor['uid'], $id, $p);
        }
        $binding = Digest::of(['provider' => $gateway->name, 'merchantId' => $gateway->merchantId]);
        $admission = Tx::run(static function () use ($actor, $kind, $id, $gateway, $binding): array {
            $context = self::context($actor, $kind, $id, F7::nowMs());
            $old = self::record($kind, $id);
            $now = F7::nowMs();
            $request = self::orderSchema()->parse((object) ['kind' => $kind, 'orderId' => $id, 'currency' => $context['currency'], 'amountMinor' => $context['amountMinor'], 'expiresAtMs' => $context['expiresAtMs'], 'idempotencyKey' => Digest::of(['kind' => $kind, 'id' => $id, 'sourceDigest' => $context['sourceDigest'], 'binding' => $binding])]);
            if ($old !== null) {
                if ($old['userId'] !== $actor['uid'] || $old['sourceDigest'] !== $context['sourceDigest'] || $old['idempotencyKey'] !== $request['idempotencyKey'] || $old['provider'] !== $gateway->name || $old['merchantId'] !== $gateway->merchantId) {
                    throw new RuntimeException('checkout_source_changed');
                }
                if ($old['status'] === 'ready' && $old['session'] !== null && $old['session']['expiresAtMs'] > $now) {
                    return ['replayed' => true, 'value' => $old['session']];
                }
                throw new RuntimeException('checkout_recovery_required');
            }
            DB::table('payment_checkout_sessions')->insert(['id' => self::key($kind, $id), 'schema' => 1, 'kind' => $kind, 'order_id' => $id, 'user_id' => $actor['uid'], 'subjects' => F7::json($context['subjects']), 'source_digest' => $context['sourceDigest'], 'provider' => $gateway->name, 'merchant_id' => $gateway->merchantId, 'idempotency_key' => $request['idempotencyKey'], 'status' => 'prepared', 'created_at_ms' => $now, 'updated_at_ms' => $now, 'version' => 1]);

            return ['replayed' => false, 'request' => $request];
        });
        if ($admission['replayed']) {
            return $admission['value'];
        }
        $request = $admission['request'];
        try {
            FinanceBarrier::run($actor['uid'], $scope, static function () use ($kind, $id): void {
                $v = self::record($kind, $id);
                if ($v['status'] !== 'prepared') {
                    throw new RuntimeException('checkout_conflict');
                }
                self::update($kind, $id, ['status' => 'dispatched', 'version' => $v['version'] + 1, 'updated_at_ms' => F7::nowMs()]);
            });
            // Kart verisi gonderilmez: yalniz siparis kimligi, tutar ve idempotency anahtari.
            $session = ExternalCalls::call([
                'kind' => 'payments', 'bindingDigest' => $binding, 'resourceKey' => Digest::of(['checkout' => $kind, 'id' => $id]),
                'resolve' => static function () use ($actor, $kind, $id, $request): array {
                    $context = self::context($actor, $kind, $id, F7::nowMs());
                    $v = self::record($kind, $id);
                    if ($v['sourceDigest'] !== $context['sourceDigest'] || $v['status'] !== 'dispatched' || $v['idempotencyKey'] !== $request['idempotencyKey']) {
                        throw new RuntimeException('checkout_source_changed');
                    }

                    return ['subjects' => $context['subjects'], 'sourceDigest' => Digest::of(['billing' => $context['sourceDigest'], 'request' => $request])];
                },
            ], static fn (): array => $gateway->checkout($request), static fn (): bool => true);
            $valid = self::sessionSchema()->parse((object) $session);
            if ($valid['expiresAtMs'] > $request['expiresAtMs'] || $valid['expiresAtMs'] <= F7::nowMs()) {
                throw new RuntimeException('checkout_expiry');
            }
            FinanceBarrier::run($actor['uid'], $scope, static function () use ($actor, $kind, $id, $valid): void {
                $context = self::context($actor, $kind, $id, F7::nowMs());
                $v = self::record($kind, $id);
                if ($v['sourceDigest'] !== $context['sourceDigest'] || $v['status'] !== 'dispatched') {
                    throw new RuntimeException('checkout_source_changed');
                }
                self::update($kind, $id, ['session_id' => $valid['sessionId'], 'session_url' => $valid['url'], 'session_expires_at_ms' => $valid['expiresAtMs'], 'status' => 'ready', 'version' => $v['version'] + 1, 'updated_at_ms' => F7::nowMs()]);
            });

            return $valid;
        } catch (Throwable $error) {
            try {
                FinanceBarrier::run($actor['uid'], $scope, static function () use ($kind, $id): void {
                    $v = self::record($kind, $id);
                    if ($v !== null && in_array($v['status'], ['prepared', 'dispatched'], true)) {
                        self::update($kind, $id, ['status' => 'uncertain', 'version' => $v['version'] + 1, 'updated_at_ms' => F7::nowMs()]);
                    }
                });
            } catch (Throwable) {
                // Mevcut kaynak/islem engel olmaya devam eder; kayip sonucta saglayici oturumu yeniden denenmez.
            }
            throw $error;
        }
    }

    /**
     * reconcileGatewayCheckout: yalniz saglayicinin acik kapanis kaniti (closed + bekleyen 0) oturumu kapatir;
     * sure dolumu veya donus yonlendirmesi kapatmaz.
     */
    public static function reconcile(string $kind, string $orderId, PaymentGateway $gateway): void
    {
        Z::enum(['business', 'brand'])->parse($kind);
        F7::uuid()->parse($orderId);
        $scope = ['kind' => $kind === 'brand' ? 'brand_order' : 'ad_order', 'id' => $orderId];
        $before = Tx::run(static fn (): ?array => self::record($kind, $orderId));
        if ($before === null) {
            return;
        }
        if ($before['kind'] !== $kind || $before['orderId'] !== $orderId) {
            throw new RuntimeException('payment_gateway_binding');
        }
        if ($before['status'] === 'closed') {
            return;
        }
        if ($before['provider'] !== $gateway->name || $before['merchantId'] !== $gateway->merchantId) {
            throw new RuntimeException('payment_gateway_binding');
        }
        $result = ExternalCalls::call([
            'kind' => 'payments', 'bindingDigest' => Digest::of(['provider' => $gateway->name, 'merchantId' => $gateway->merchantId]),
            'resourceKey' => Digest::of(['kind' => $kind, 'orderId' => $orderId, 'action' => 'checkout_closure']),
            'resolve' => static fn (): array => ['subjects' => FinanceBarrier::assert(null, $scope), 'sourceDigest' => FinanceBarrier::lookupBinding($scope['kind'], $scope['id'])],
        ], static fn (): array => $gateway->lookupSession($kind, $orderId, F7::hash()->parse($before['idempotencyKey'])), static fn (): bool => true);
        if ($result['state'] !== 'closed' || $result['pendingPayments'] !== 0) {
            throw new RuntimeException('checkout_not_closed');
        }
        FinanceBarrier::run(null, $scope, static function () use ($kind, $orderId, $before): void {
            $current = self::record($kind, $orderId);
            if ($current === null || Digest::of($current) !== Digest::of($before)) {
                throw new RuntimeException('checkout_source_changed');
            }
            self::update($kind, $orderId, ['status' => 'closed', 'version' => $current['version'] + 1, 'updated_at_ms' => F7::nowMs()]);
        });
    }
}
