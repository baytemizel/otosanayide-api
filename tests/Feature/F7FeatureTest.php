<?php

use App\Domain\Auth\Actor;
use App\Domain\Payments\PaymentGateway;
use App\Http\Controllers\Api\Payments\Gate;
use Illuminate\Support\Facades\Http;

// Veritabanina ulasmadan dogrulanabilen F7 sirasi: origin, sorgu, icerik turu, jeton, bayrak, imza.

function f7call($t, string $m, string $u, array $s = [], ?string $b = null)
{
    return $t->call($m, $u, [], [], [], $s + ['HTTP_ACCEPT_LANGUAGE' => ''], $b);
}

const F7_ROUTES = [
    ['GET', '/api/admin/ad-tariffs'], ['PATCH', '/api/admin/ad-tariffs'], ['GET', '/api/admin/brand-acceptance'], ['POST', '/api/admin/brand-acceptance'],
    ['GET', '/api/admin/brand-campaigns'], ['PATCH', '/api/admin/brand-campaigns'], ['GET', '/api/admin/brand-cancellation-history'], ['GET', '/api/admin/brand-cancellation-queue'],
    ['GET', '/api/admin/brand-cancellations'], ['POST', '/api/admin/brand-cancellations'], ['PATCH', '/api/admin/brand-cancellations'], ['PATCH', '/api/admin/brand-capacities'],
    ['GET', '/api/admin/brand-media'], ['POST', '/api/admin/brand-media'], ['GET', '/api/admin/brand-metrics'], ['GET', '/api/admin/brand-orders'], ['POST', '/api/admin/brand-orders'],
    ['GET', '/api/admin/brand-payment-review'], ['PATCH', '/api/admin/brand-payment-review'], ['GET', '/api/admin/brand-preview'], ['GET', '/api/admin/brand-preview/image'],
    ['POST', '/api/admin/brand-quote'], ['GET', '/api/admin/brand-refund-review'], ['PATCH', '/api/admin/brand-refund-review'], ['GET', '/api/admin/brand-reservations'],
    ['POST', '/api/admin/brand-reservations'], ['DELETE', '/api/admin/brand-reservations'], ['GET', '/api/admin/brand-tariffs'], ['PATCH', '/api/admin/brand-tariffs'],
    ['GET', '/api/admin/payment-cases'], ['PATCH', '/api/admin/payment-cases'], ['PATCH', '/api/campaigns'], ['GET', '/api/campaigns/metrics'],
    ['GET', '/api/campaigns/reservation'], ['POST', '/api/campaigns/reservation'], ['POST', '/api/campaigns/finance'], ['POST', '/api/payments/checkout'],
];

it('requires a token on every authenticated F7 method (anonymous)', function (string $m, string $u) {
    f7call($this, $m, $u, ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
})->with(F7_ROUTES);

it('rejects a malformed bearer before any finance decision', function () {
    foreach (['Basic abc', 'Bearer'] as $h) {
        f7call($this, 'GET', '/api/admin/payment-cases', ['HTTP_AUTHORIZATION' => $h])->assertStatus(401);
    }
});

it('maps the finance role matrix exactly like financeAllowed', function () {
    $actor = fn (bool $verified, bool $admin, bool $finance) => new Actor('u1', 'u@example.test', $verified, $admin, false, $finance, null, 0, 0, 's');
    expect(Gate::financeAllowed($actor(true, true, true)))->toBeTrue();
    foreach ([[false, true, true], [true, false, true], [true, true, false], [false, false, false]] as [$v, $a, $f]) {
        expect(Gate::financeAllowed($actor($v, $a, $f)))->toBeFalse();
    }
});

it('validates campaign queries and content type before authentication', function () {
    f7call($this, 'GET', '/api/campaigns')->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    f7call($this, 'GET', '/api/campaigns?businessId=b1&scope=bad')->assertStatus(400);
    f7call($this, 'PATCH', '/api/campaigns', ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    f7call($this, 'PATCH', '/api/campaigns?x=1', ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);
    f7call($this, 'GET', '/api/campaigns/finance?businessId=b1&campaignId=x&campaignVersion=1')->assertStatus(400);
    f7call($this, 'GET', '/api/campaigns/finance?businessId=b1&campaignId=11111111-1111-4111-8111-111111111111&campaignVersion=1.5')->assertStatus(400);
    f7call($this, 'POST', '/api/campaigns/finance', ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    f7call($this, 'GET', '/api/campaigns/owner-metrics?businessId=b1&campaignId=11111111-1111-4111-8111-111111111111&campaignVersion=1')->assertStatus(401);
});

it('refuses foreign origins on browser-facing F7 routes and reflects allowed ones', function () {
    $evil = ['HTTP_ORIGIN' => 'https://evil.test'];
    f7call($this, 'GET', '/api/campaigns?businessId=b1', $evil)->assertStatus(403);
    f7call($this, 'GET', '/api/campaigns/finance', $evil)->assertStatus(403);
    f7call($this, 'POST', '/api/payments/checkout', $evil)->assertStatus(403);
    f7call($this, 'POST', '/api/payments/checkout?x=1')->assertStatus(403);
    $ok = f7call($this, 'GET', '/api/campaigns?businessId=b1', ['HTTP_ORIGIN' => 'https://otosanayide.test']);
    $ok->assertStatus(401);
    expect($ok->headers->get('Access-Control-Allow-Origin'))->toBe('https://otosanayide.test')->and($ok->headers->get('Cache-Control'))->toBe('private, no-store');
});

it('keeps flag-off answers identical to the source', function () {
    f7call($this, 'POST', '/api/ad-events')->assertStatus(204);
    f7call($this, 'POST', '/api/brand-events')->assertStatus(204);
    f7call($this, 'GET', '/api/brand-banners/image?token=abc')->assertStatus(404);
    f7call($this, 'GET', '/api/brand-banners?placement=category_banner&city=%C4%B0stanbul&category=')->assertStatus(400);
    f7call($this, 'POST', '/api/payments/webhook')->assertStatus(503)->assertExactJson(['received' => false]);
});

it('ad events need the exact web origin and a valid sealed token when enabled', function () {
    config(['otosanayide.flags.ad_metrics' => true, 'otosanayide.flags.ad_serving' => true, 'ads.ad_metrics_secret' => str_repeat('ab', 32)]);
    f7call($this, 'POST', '/api/ad-events', ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(403);
    f7call($this, 'POST', '/api/ad-events', ['HTTP_ORIGIN' => 'https://evil.test'], '{}')->assertStatus(403);
    $r = f7call($this, 'POST', '/api/ad-events', ['HTTP_ORIGIN' => 'https://otosanayide.test', 'CONTENT_TYPE' => 'application/json'], '{"token":"'.str_repeat('A', 60).'","kind":"view"}');
    $r->assertStatus(400);
    expect($r->getContent())->toBe('');
});

it('brand serving stays unconfigured without an app attestation provider', function () {
    config(['otosanayide.flags.brand_serving' => true, 'otosanayide.flags.brand_media' => true, 'ads.brand_media_disk' => 'brand', 'ads.brand_serving_secret' => str_repeat('ab', 32), 'ads.brand_public_app_ids' => 'app-1']);
    $r = f7call($this, 'GET', '/api/brand-banners?placement=home_banner&city=%C4%B0stanbul&category=');
    $r->assertStatus(503)->assertExactJson(['error' => 'Reklam sunumu henüz yapılandırılmadı.']);
    expect($r->headers->get('Vary'))->toBe('X-Firebase-AppCheck');
    f7call($this, 'GET', '/api/brand-banners?placement=home_banner')->assertStatus(400);
    f7call($this, 'GET', '/api/brand-banners/image')->assertStatus(400);
    f7call($this, 'GET', '/api/brand-banners/image?token=abc')->assertStatus(503);
    config(['otosanayide.flags.brand_metrics' => true]);
    f7call($this, 'POST', '/api/brand-events', ['HTTP_ORIGIN' => 'https://evil.test'], '{}')->assertStatus(403);
    f7call($this, 'POST', '/api/brand-events', [], '{}')->assertStatus(400);
});

function f7gateway(): void
{
    config(['otosanayide.flags.payments' => true, 'ads.payment' => ['name' => 'relay', 'merchant_id' => 'm-1', 'origin' => 'https://gateway.example.test', 'token' => str_repeat('t', 40), 'webhook_secret' => str_repeat('w', 40), 'return_origin' => 'https://otosanayide.test', 'checkout_origins' => 'https://checkout.example.test']]);
}

it('webhook rejects unsigned, tampered, stale or query-bearing deliveries with 401', function () {
    f7gateway();
    $raw = '{"eventId":"evt_1","kind":"business","orderId":"11111111-1111-4111-8111-111111111111","paymentId":"p1","type":"payment.updated"}';
    $ts = (string) time();
    $sig = 'v1='.hash_hmac('sha256', $ts.'.'.$raw, str_repeat('w', 40));
    $h = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYMENT_TIMESTAMP' => $ts, 'HTTP_X_PAYMENT_SIGNATURE' => $sig];
    f7call($this, 'POST', '/api/payments/webhook', ['CONTENT_TYPE' => 'application/json'], $raw)->assertStatus(401)->assertExactJson(['received' => false]);
    f7call($this, 'POST', '/api/payments/webhook', $h, $raw.' ')->assertStatus(401);
    f7call($this, 'POST', '/api/payments/webhook?x=1', $h, $raw)->assertStatus(401);
    f7call($this, 'POST', '/api/payments/webhook', ['CONTENT_TYPE' => 'text/plain'] + $h, $raw)->assertStatus(401);
    $old = (string) (time() - 301);
    f7call($this, 'POST', '/api/payments/webhook', ['HTTP_X_PAYMENT_TIMESTAMP' => $old, 'HTTP_X_PAYMENT_SIGNATURE' => 'v1='.hash_hmac('sha256', $old.'.'.$raw, str_repeat('w', 40))] + $h, $raw)->assertStatus(401);
    // Imza gecer; kalici gelen kutusu (veritabani) erisilemezse olay asla onaylanmaz.
    f7call($this, 'POST', '/api/payments/webhook', $h, $raw)->assertStatus(503)->assertExactJson(['received' => false]);
});

it('treats an incomplete gateway configuration as payments disabled', function () {
    config(['otosanayide.flags.payments' => true, 'ads.payment' => ['name' => 'relay', 'merchant_id' => 'm-1', 'origin' => 'http://gateway.example.test', 'token' => str_repeat('t', 40), 'webhook_secret' => str_repeat('w', 40), 'return_origin' => 'https://otosanayide.test', 'checkout_origins' => '']]);
    expect(PaymentGateway::configured())->toBeNull();
    f7gateway();
    expect(PaymentGateway::configured()['gateway']->name)->toBe('relay');
});

it('HTTP gateway contract binds identity, sends no card fields and validates responses', function () {
    f7gateway();
    $gw = PaymentGateway::configured()['gateway'];
    $order = ['kind' => 'business', 'orderId' => '11111111-1111-4111-8111-111111111111', 'currency' => 'TRY', 'amountMinor' => 1010, 'expiresAtMs' => (int) (microtime(true) * 1000) + 600000, 'idempotencyKey' => str_repeat('a', 64)];
    Http::fake([
        'gateway.example.test/v1/checkout/sessions' => Http::response(['sessionId' => 's1', 'url' => 'https://checkout.example.test/pay/s1', 'expiresAtMs' => $order['expiresAtMs'] - 1000]),
        'gateway.example.test/v1/payments/lookup' => Http::response(['paymentId' => 'p1', 'merchantId' => 'other', 'orderId' => $order['orderId'], 'status' => 'succeeded', 'currency' => 'TRY', 'amountMinor' => 1010, 'capturedAtMs' => 1]),
        'gateway.example.test/v1/checkout/lookup' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
    ]);
    expect($gw->checkout($order)['sessionId'])->toBe('s1');
    Http::assertSent(function ($req) use ($order) {
        if (! str_ends_with($req->url(), '/v1/checkout/sessions')) {
            return false;
        }
        $body = json_decode($req->body(), true);

        return $req->hasHeader('Idempotency-Key', $order['idempotencyKey']) && $req->hasHeader('Authorization', 'Bearer '.str_repeat('t', 40))
            && $body['merchantId'] === 'm-1' && $body['returnUrl'] === 'https://otosanayide.test/isletme?payment=returned'
            && array_diff(array_keys($body), ['merchantId', 'kind', 'orderId', 'currency', 'amountMinor', 'expiresAtMs', 'idempotencyKey', 'returnUrl']) === [];
    });
    expect(fn () => $gw->lookup('business', $order['orderId'], 'p1'))->toThrow(RuntimeException::class, 'payment_gateway_binding');
    expect(fn () => $gw->lookupSession('business', $order['orderId'], $order['idempotencyKey']))->toThrow(RuntimeException::class, 'payment_gateway_unavailable');
});

it('compiles idempotency and replay keys as primary or unique constraints', function () {
    $sql = '';
    DB::connection()->setPdo(new class extends PDO
    {
        public function __construct() {}

        public function getAttribute(int $attribute): mixed
        {
            return '8.4.13';
        }
    });
    foreach (['2026_11_11_070001_create_ad_campaign_tables.php', '2026_11_11_070002_create_brand_tables.php', '2026_11_11_070003_create_payment_gateway_tables.php'] as $f) {
        $m = require database_path('migrations/'.$f);
        foreach (DB::connection()->pretend(fn () => $m->up()) as $q) {
            $sql .= $q['query']."\n";
        }
    }
    expect($sql)->toContain('alter table `ad_orders` add unique `ad_orders_reservation_id_unique`(`reservation_id`)');
    foreach (['payment_gateway_inbox' => '`id`', 'payment_checkout_sessions' => '`id`', 'brand_payment_case_history' => '`case_id`, `version`', 'ad_inventory_days' => '`market_id`, `date`', 'brand_refund_review_history' => '`review_id`, `version`', 'ad_payment_receipts' => '`id`', 'brand_acceptances' => '`reservation_id`'] as $table => $key) {
        expect($sql)->toMatch('/create table `'.$table.'` \\(.*primary key \\('.preg_quote($key, '/').'\\)\\)/');
    }
    expect($sql)->not->toContain('card');
});
