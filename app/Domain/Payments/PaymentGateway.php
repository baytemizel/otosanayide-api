<?php

namespace App\Domain\Payments;

use App\Domain\Brand\BrandCancellation;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * packages/server/payment-gateway.ts — normalized gateway v1.
 * Bu BIZIM sunucudan sunucuya relay sozlesmemizdir; belirli bir banka/odeme kurulusunun bu API'yi
 * uyguladigi iddia edilmez. Secilen kurulus bu sozlesmeye baglanip sandbox'ta kabul edilmeden acilmaz.
 * Kart verisi bu sunucuya hic girmez; yalniz HTTPS izinli hosted checkout origin'ine yonlendirilir.
 */
class PaymentGateway
{
    /** @param list<string> $origins */
    public function __construct(
        public readonly string $name,
        public readonly string $merchantId,
        private readonly string $origin,
        private readonly string $token,
        private readonly string $returnOrigin,
        private readonly array $origins,
    ) {}

    /** searchOrigin(): yalniz kamuya acik HTTPS origin. */
    public static function httpsOrigin(string $raw): string
    {
        $u = parse_url($raw);
        if (! is_array($u) || strtolower($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || isset($u['port']) || ! isset($u['host']) || ($u['path'] ?? '/') !== '/' || str_contains($raw, '#') || str_contains($raw, '?')) {
            throw new RuntimeException('search_config');
        }
        $host = strtolower($u['host']);
        if (preg_match('/^(?:localhost|127\.|0\.|10\.|192\.168\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.|\[)/iD', $host) === 1) {
            throw new RuntimeException('search_config');
        }

        return 'https://'.$host;
    }

    public static function configSchema(): Z
    {
        return Z::strictObject([
            'name' => Z::string()->regex('/^[a-z0-9_-]{1,40}$/'), 'merchantId' => Z::string()->min(1)->max(200), 'origin' => Z::string(),
            'token' => Z::string()->min(32)->max(4096), 'webhookSecret' => Z::string()->min(32)->max(4096), 'returnOrigin' => Z::string(),
            'checkoutOrigins' => Z::array(Z::string())->min(1)->max(10),
        ]);
    }

    /** httpPaymentGateway(config) */
    public static function fromConfig(mixed $raw): self
    {
        $c = self::configSchema()->parse($raw);

        return new self($c['name'], $c['merchantId'], self::httpsOrigin($c['origin']), $c['token'], self::httpsOrigin($c['returnOrigin']), array_map([self::class, 'httpsOrigin'], $c['checkoutOrigins']));
    }

    /**
     * configuredPaymentGateway(): PAYMENTS_ENABLED kapali veya ayar eksikse null.
     *
     * @return array{gateway:self,webhookSecret:string}|null
     */
    public static function configured(): ?array
    {
        if (! F7::flag('payments')) {
            return null;
        }
        $p = config('ads.payment');
        try {
            $origins = array_values(array_filter(array_map('trim', explode(',', (string) ($p['checkout_origins'] ?? ''))), static fn (string $v): bool => $v !== ''));
            $raw = (object) array_filter(['name' => $p['name'] ?? null, 'merchantId' => $p['merchant_id'] ?? null, 'origin' => $p['origin'] ?? null, 'token' => $p['token'] ?? null, 'webhookSecret' => $p['webhook_secret'] ?? null, 'returnOrigin' => $p['return_origin'] ?? null], static fn (mixed $v): bool => $v !== null);
            $raw->checkoutOrigins = $origins;
            $gateway = app()->bound(self::class) ? app(self::class) : self::fromConfig($raw);

            return ['gateway' => $gateway, 'webhookSecret' => self::configSchema()->parse($raw)['webhookSecret']];
        } catch (Throwable) {
            return null;
        }
    }

    private function call(string $path, array $payload, ?string $idempotencyKey = null): mixed
    {
        $headers = ['Authorization' => 'Bearer '.$this->token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'] + ($idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : []);
        $r = Http::withHeaders($headers)->timeout(12)->withoutRedirecting()->withBody(F7::json(['merchantId' => $this->merchantId] + $payload), 'application/json')->post($this->origin.'/v1/'.$path);
        if (! $r->successful() || ! str_contains((string) $r->header('Content-Type'), 'application/json')) {
            throw new RuntimeException('payment_gateway_unavailable');
        }
        $body = $r->body();
        if (strlen($body) > 32000) {
            throw new RuntimeException('payment_gateway_unavailable');
        }

        return json_decode($body, false, 64, JSON_THROW_ON_ERROR);
    }

    /** checkout: yonlendirme adresi izinli HTTPS origin, suresi siparisten kisa olmali. */
    public function checkout(array $order): array
    {
        $o = Checkout::orderSchema()->parse((object) $order);

        return Checkout::validateRedirect($this->call('checkout/sessions', $o + ['returnUrl' => $this->returnOrigin.'/isletme?payment=returned'], $o['idempotencyKey']), $this->origins, F7::nowMs(), $o);
    }

    public function lookup(string $kind, string $orderId, string $paymentId): stdClass
    {
        F7::uuid()->parse($orderId);
        Z::string()->min(1)->max(200)->parse($paymentId);
        Z::enum(['business', 'brand'])->parse($kind);
        $raw = $this->call('payments/lookup', ['kind' => $kind, 'orderId' => $orderId, 'paymentId' => $paymentId]);
        $result = PaymentProvider::lookupSchema(18_000_000_000)->parse($raw);
        if ($result['merchantId'] !== $this->merchantId || $result['orderId'] !== $orderId || $result['paymentId'] !== $paymentId) {
            throw new RuntimeException('payment_gateway_binding');
        }

        return $raw;
    }

    /** @return array{state:string,pendingPayments:int} */
    public function lookupSession(string $kind, string $orderId, string $idempotencyKey): array
    {
        $result = Z::strictObject(['merchantId' => Z::string(), 'orderId' => F7::uuid(), 'idempotencyKey' => Z::string(), 'state' => Z::enum(['active', 'closed', 'unknown']), 'pendingPayments' => Z::number()->int()->nonnegative()->max(F7::SAFE)])
            ->parse($this->call('checkout/lookup', ['kind' => $kind, 'orderId' => $orderId, 'idempotencyKey' => $idempotencyKey]));
        if ($result['merchantId'] !== $this->merchantId || $result['orderId'] !== $orderId || $result['idempotencyKey'] !== $idempotencyKey) {
            throw new RuntimeException('payment_gateway_binding');
        }

        return ['state' => $result['state'], 'pendingPayments' => $result['pendingPayments']];
    }

    public function lookupRefund(string $refundId): stdClass
    {
        Z::string()->min(1)->max(200)->parse($refundId);
        $raw = $this->call('refunds/lookup', ['refundId' => $refundId]);
        $result = BrandCancellation::refundResult()->parse($raw);
        if ($result['refundId'] !== $refundId || $result['merchantId'] !== $this->merchantId) {
            throw new RuntimeException('payment_gateway_binding');
        }

        return $raw;
    }

    /** gatewayPaymentEvent */
    public static function eventSchema(): Z
    {
        return Z::strictObject(['eventId' => Z::string()->regex('/^[A-Za-z0-9_-]{1,128}$/'), 'kind' => Z::enum(['business', 'brand']), 'orderId' => F7::uuid(), 'paymentId' => Z::string()->min(1)->max(200), 'type' => Z::literal('payment.updated')]);
    }

    /**
     * verifyGatewayWebhook: HMAC-SHA256(secret, timestamp + "." + ham govde), 300 sn pencere, sabit zamanli karsilastirma.
     *
     * @return array{eventId:string,kind:string,orderId:string,paymentId:string,type:string}
     */
    public static function verifyWebhook(string $raw, string $timestamp, string $signature, string $secret, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        if (strlen($raw) > 20000 || preg_match('/^\d{10}$/D', $timestamp) !== 1 || preg_match('/^v1=[a-f0-9]{64}$/D', $signature) !== 1 || strlen($secret) < 32 || abs(intdiv($now, 1000) - (int) $timestamp) > 300) {
            throw new RuntimeException('payment_webhook_auth');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret, true);
        if (! hash_equals($expected, (string) hex2bin(substr($signature, 3)))) {
            throw new RuntimeException('payment_webhook_auth');
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new RuntimeException('payment_webhook_auth');
        }
        try {
            return self::eventSchema()->parse(json_decode($raw, false, 64, JSON_THROW_ON_ERROR));
        } catch (ZodError|\JsonException) {
            throw new RuntimeException('payment_webhook_auth');
        }
    }
}
