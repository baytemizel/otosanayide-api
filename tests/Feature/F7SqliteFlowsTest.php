<?php

use App\Domain\Ads\AdPayments;
use App\Domain\Ads\AdRules;
use App\Domain\Ads\CampaignRules;
use App\Domain\Ads\SponsoredServing;
use App\Domain\Catalogue\SearchFilter;
use App\Domain\Catalogue\SearchService;
use App\Domain\Payments\F7;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentProvider;
use App\Domain\Payments\Webhook;
use App\Support\Query;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/*
 * F7'nin iki kritik akisi + sponsorlu sunum, surec ici SQLite (tests/Support/R2Db.php) uzerinde.
 * MySQL kabulu DEGILDIR. ExternalCalls'taki `ON DUPLICATE KEY UPDATE` yalniz bu test PDO'sunda SQLite karsiligina cevrilir.
 */

final class F7SqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace('ON DUPLICATE KEY UPDATE `owner_uid` = VALUES(`owner_uid`)', 'ON CONFLICT(`owner_uid`) DO NOTHING', $query), $options);
    }
}

const F7S_CAMPAIGN = '11111111-1111-4111-8111-111111111111';
const F7S_RES = '22222222-2222-4222-8222-222222222222';

function f7sSetup(int $now): array
{
    r2Db(new F7SqlitePdo('sqlite::memory:'));
    $s = Schema::connection('r2');
    $s->create('ad_campaigns', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('business_id'), $t->string('owner_uid')->nullable(), $t->string('placement'), $t->string('city'), $t->string('category'), $t->integer('days'), $t->string('start_date'), $t->string('headline'), $t->string('description'), $t->string('business_name')->default(''), $t->integer('approved_profile_version')->nullable(), $t->string('status'), $t->integer('version'), $t->text('reason'), $t->bigInteger('created_at_ms'), $t->bigInteger('updated_at_ms')]);
    $s->create('ad_reservations', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('user_id'), $t->string('campaign_id'), $t->string('business_id'), $t->string('market_id'), $t->integer('campaign_version'), $t->integer('tariff_version'), $t->string('currency'), $t->integer('subtotal_minor'), $t->integer('tax_minor'), $t->integer('total_minor'), $t->text('dates'), $t->string('status'), $t->bigInteger('expires_at_ms'), $t->bigInteger('created_at_ms'), $t->bigInteger('released_at_ms')->nullable(), $t->string('order_id')->nullable()]);
    $s->create('ad_orders', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('user_id'), $t->string('reservation_id'), $t->string('campaign_id'), $t->string('business_id'), $t->integer('campaign_version'), $t->string('provider'), $t->string('merchant_id'), $t->string('currency'), $t->integer('subtotal_minor'), $t->integer('tax_minor'), $t->integer('total_minor'), $t->string('status'), $t->string('reason')->nullable(), $t->string('receipt_id')->nullable(), $t->bigInteger('created_at_ms'), $t->bigInteger('checked_at_ms')->nullable(), $t->bigInteger('paid_at_ms')->nullable()]);
    $s->create('ad_payment_receipts', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('order_id'), $t->string('provider'), $t->string('payment_id'), $t->string('currency'), $t->integer('amount_minor'), $t->string('outcome'), $t->string('reason'), $t->bigInteger('captured_at_ms')->nullable(), $t->bigInteger('checked_at_ms')]);
    $s->create('ad_payment_cases', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('order_id'), $t->string('receipt_id'), $t->string('reason'), $t->string('status'), $t->integer('version'), $t->bigInteger('created_at_ms')]);
    $s->create('ad_campaign_reservations', fn (Blueprint $t) => [$t->string('campaign_id')->primary(), $t->string('reservation_id'), $t->bigInteger('expires_at_ms')]);
    $s->create('ad_inventory_days', fn (Blueprint $t) => [$t->string('market_id'), $t->string('date'), $t->text('holds'), $t->primary(['market_id', 'date'])]);
    $s->create('payment_gateway_inbox', fn (Blueprint $t) => [$t->string('id')->primary(), $t->integer('schema'), $t->string('provider'), $t->string('merchant_id'), $t->string('event_id'), $t->string('event_digest'), $t->string('kind'), $t->string('order_id'), $t->string('payment_id'), $t->text('subjects'), $t->string('status'), $t->string('lease_id'), $t->bigInteger('lease_until_ms'), $t->integer('attempts'), $t->string('result')->nullable()]);
    $s->create('account_external_barriers', fn (Blueprint $t) => [$t->string('owner_uid')->primary(), $t->integer('active')->default(0), $t->integer('uncertain')->default(0), $t->integer('sequence')->default(0)]);
    $s->create('account_external_operations', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('kind'), $t->string('binding_digest'), $t->string('resource_key'), $t->string('source_digest'), $t->string('state'), $t->integer('version')->default(1), $t->bigInteger('created_at_ms'), $t->bigInteger('updated_at_ms')]);
    $s->create('account_external_operation_subjects', fn (Blueprint $t) => [$t->string('operation_id'), $t->string('uid'), $t->primary(['operation_id', 'uid'])]);

    r2User('o1');
    r2Business('b1', 'o1');
    $start = F7::isoDate($now + 3 * 3600000 + 2 * F7::DAY);
    $dates = CampaignRules::dates($start, 7);
    $market = AdRules::marketId(['placement' => 'home', 'city' => 'İstanbul', 'category' => 'mechanical']);
    $expires = $now + 10 * 60000;
    DB::table('ad_campaigns')->insert(['id' => F7S_CAMPAIGN, 'business_id' => 'b1', 'owner_uid' => 'o1', 'placement' => 'home', 'city' => 'İstanbul', 'category' => 'mechanical', 'days' => 7, 'start_date' => $start, 'headline' => 'Sentetik kampanya', 'description' => 'Sentetik açıklama metni', 'approved_profile_version' => 0, 'status' => 'approved', 'version' => 2, 'reason' => '', 'created_at_ms' => $now - 1000, 'updated_at_ms' => $now - 1000]);
    DB::table('ad_reservations')->insert(['id' => F7S_RES, 'user_id' => 'o1', 'campaign_id' => F7S_CAMPAIGN, 'business_id' => 'b1', 'market_id' => $market, 'campaign_version' => 2, 'tariff_version' => 1, 'currency' => 'TRY', 'subtotal_minor' => 100000, 'tax_minor' => 20000, 'total_minor' => 120000, 'dates' => json_encode($dates), 'status' => 'held', 'expires_at_ms' => $expires, 'created_at_ms' => $now - 1000]);
    DB::table('ad_campaign_reservations')->insert(['campaign_id' => F7S_CAMPAIGN, 'reservation_id' => F7S_RES, 'expires_at_ms' => $expires]);
    foreach ($dates as $d) {
        DB::table('ad_inventory_days')->insert(['market_id' => $market, 'date' => $d, 'holds' => json_encode([['reservationId' => F7S_RES, 'expiresAtMs' => $expires]])]);
    }

    return ['start' => $start, 'dates' => $dates, 'market' => $market];
}

function f7sProvider(array $result, int &$calls): PaymentProvider
{
    return new PaymentProvider('testpay', 'merchant-1', function (string $paymentId) use ($result, &$calls): object {
        $calls++;

        return (object) ($result + ['paymentId' => $paymentId, 'merchantId' => 'merchant-1', 'orderId' => F7S_RES]);
    });
}

it('reservation -> order -> settle is idempotent and schedules the campaign once', function () {
    $now = F7::nowMs();
    f7sSetup($now);
    $calls = 0;
    $provider = f7sProvider(['status' => 'succeeded', 'currency' => 'TRY', 'amountMinor' => 120000, 'capturedAtMs' => $now], $calls);
    $order = AdPayments::createOrder('o1', F7S_RES, $provider, $now);
    expect($order)->toMatchArray(['status' => 'pending', 'totalMinor' => 120000]);
    expect(AdPayments::createOrder('o1', F7S_RES, $provider, $now)['status'])->toBe('pending'); // ayni siparis
    expect(DB::table('ad_orders')->count())->toBe(1);

    expect(AdPayments::settle(F7S_RES, $provider, 'pay_1', $now + 1))->toBe(['status' => 'paid', 'replayed' => false]);
    expect(AdPayments::settle(F7S_RES, $provider, 'pay_1', $now + 2))->toBe(['status' => 'paid', 'replayed' => true]);
    expect(DB::table('ad_payment_receipts')->count())->toBe(1)
        ->and(DB::table('ad_campaigns')->value('status'))->toBe('scheduled')
        ->and((int) DB::table('ad_campaigns')->value('version'))->toBe(3)
        ->and(DB::table('ad_reservations')->value('status'))->toBe('paid');

    // Basarisiz bildirim sonuclanmis siparisi geri cekmez; ikinci odeme incelemeye duser.
    $failCalls = 0;
    expect(AdPayments::settle(F7S_RES, f7sProvider(['status' => 'failed', 'currency' => 'TRY', 'amountMinor' => 0, 'capturedAtMs' => null], $failCalls), 'pay_2', $now + 3)['status'])->toBe('paid');
    expect(AdPayments::settle(F7S_RES, $provider, 'pay_3', $now + 4))->toBe(['status' => 'needs_review', 'replayed' => false]);
    expect(DB::table('ad_orders')->value('status'))->toBe('paid')->and(DB::table('ad_payment_cases')->value('reason'))->toBe('additional_payment');
});

it('webhook inbox applies an event once and replays duplicates without a second lookup', function () {
    $now = F7::nowMs();
    f7sSetup($now);
    $calls = 0;
    AdPayments::createOrder('o1', F7S_RES, f7sProvider(['status' => 'pending', 'currency' => 'TRY', 'amountMinor' => 0, 'capturedAtMs' => null], $calls), $now);
    Http::fake(['pay.example.test/v1/payments/lookup' => Http::response(['paymentId' => 'pay_9', 'merchantId' => 'merchant-1', 'orderId' => F7S_RES, 'status' => 'succeeded', 'currency' => 'TRY', 'amountMinor' => 120000, 'capturedAtMs' => $now])]);
    $gateway = new PaymentGateway('testpay', 'merchant-1', 'https://pay.example.test', 'token', 'https://otosanayide.test', ['https://pay.example.test']);
    $event = ['eventId' => 'evt_1', 'kind' => 'business', 'orderId' => F7S_RES, 'paymentId' => 'pay_9', 'type' => 'payment.updated'];
    expect(Webhook::apply($event, $gateway))->toBe(['received' => true, 'replayed' => false]);
    expect(Webhook::apply($event, $gateway))->toBe(['received' => true, 'replayed' => true]);
    Http::assertSentCount(1);
    expect(DB::table('payment_gateway_inbox')->value('status'))->toBe('applied')->and(DB::table('ad_orders')->value('status'))->toBe('paid');
    expect(fn () => Webhook::apply(['paymentId' => 'pay_other'] + $event, $gateway))->toThrow(RuntimeException::class, 'payment_event_conflict');
});

it('serves a paid campaign as a labelled sponsored item only when ad serving is on', function () {
    $now = F7::nowMs();
    $setup = f7sSetup($now);
    $calls = 0;
    $provider = f7sProvider(['status' => 'succeeded', 'currency' => 'TRY', 'amountMinor' => 120000, 'capturedAtMs' => $now], $calls);
    AdPayments::createOrder('o1', F7S_RES, $provider, $now);
    AdPayments::settle(F7S_RES, $provider, 'pay_1', $now + 1);
    $home = SearchFilter::parse(Query::parse('city='.rawurlencode('İstanbul')));
    $during = F7::trStart($setup['start']) + 3600000;
    $items = SponsoredServing::serve($home, app(SearchService::class), $during);
    expect($items)->toHaveCount(1)->and(array_keys($items[0]))->toBe(['business', 'headline', 'description', 'expiresAtMs'])
        ->and($items[0]['business']['id'])->toBe('b1')->and($items[0]['headline'])->toBe('Sentetik kampanya');
    // v1 arama yaniti sponsorlu ogeyle core search-client katı semasindan gecmeli (scripts/contract).
    contract('search-sponsored', 'search', ['items' => [], 'districts' => [], 'mode' => 'live', 'sponsored' => $items]);
    expect(SponsoredServing::serve($home, app(SearchService::class), $now))->toBe([]); // kampanya baslamadi
    expect(SponsoredServing::serve(SearchFilter::parse(Query::parse('')), app(SearchService::class), $during))->toBe([]); // il yok
    expect(SponsoredServing::placement(SearchFilter::parse(Query::parse('city='.rawurlencode('İstanbul').'&q=fren'))))->toBe('search');
});
