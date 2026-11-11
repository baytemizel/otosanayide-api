<?php

use App\Domain\Ads\AdMetrics;
use App\Domain\Ads\AdRules;
use App\Domain\Ads\CampaignRules;
use App\Domain\Ads\OwnerAdFinance;
use App\Domain\Brand\BrandCancellation;
use App\Domain\Brand\BrandMediaCleanup;
use App\Domain\Brand\BrandRefundReview;
use App\Domain\Brand\BrandReview;
use App\Domain\Brand\BrandRules;
use App\Domain\Brand\BrandServing;
use App\Domain\Payments\Checkout;
use App\Domain\Payments\F7;
use App\Domain\Payments\PaymentCases;
use App\Domain\Payments\PaymentGateway;
use App\Support\Body;
use App\Support\Query;
use App\Support\ZodError;

// Kaynak packages/core/*.test.ts senaryolarinin saf kural karsiliklari (veritabani yok).

function f7now(): int
{
    return F7::trStart('2026-10-19') + 10 * 3600000;
}

function f7json(array $v): mixed
{
    return Body::decode(json_encode($v, JSON_UNESCAPED_UNICODE));
}

function f7value(array $patch = []): array
{
    return $patch + ['placement' => 'search', 'city' => 'İstanbul', 'category' => 'mechanical', 'days' => 7, 'startDate' => '2026-10-20', 'headline' => 'Periyodik bakım hizmeti', 'description' => 'Aracınız için periyodik bakım hizmetleri.'];
}

function f7business(array $patch = []): array
{
    return $patch + ['name' => 'Test Atölyesi', 'ownership' => 'verified', 'published' => true, 'coordinateStatus' => 'verified', 'latitude' => 41, 'longitude' => 29, 'city' => 'İstanbul', 'categories' => ['mechanical']];
}

const F7_CID = '00000000-0000-4000-8000-000000000001';
const F7_RID = '00000000-0000-4000-8000-000000000011';

function f7saved(): array
{
    return CampaignRules::transition(null, ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 0, 'action' => 'save', 'value' => f7value()], f7business(), ['owner' => true, 'admin' => false, 'affiliated' => true], f7now());
}

function f7pending(): array
{
    return CampaignRules::transition(f7saved(), ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 1, 'action' => 'submit'], f7business(), ['owner' => true, 'admin' => false, 'affiliated' => true], f7now());
}

function f7rejects(callable $f): void
{
    $thrown = false;
    try {
        $f();
    } catch (Throwable) {
        $thrown = true;
    }
    expect($thrown)->toBeTrue();
}

it('campaign input rejects price/status injection and invalid date/category', function () {
    f7rejects(fn () => CampaignRules::input()->parse(f7json(f7value(['amount' => 1]))));
    f7rejects(fn () => CampaignRules::input()->parse(f7json(f7value(['startDate' => '2026-02-30']))));
    f7rejects(fn () => CampaignRules::input()->parse(f7json(f7value(['category' => 'arbitrary']))));
    f7rejects(fn () => CampaignRules::action()->parse(f7json(['id' => F7_CID, 'businessId' => 'b', 'version' => 0, 'action' => 'activate'])));
    f7rejects(fn () => CampaignRules::action()->parse(f7json(['id' => F7_CID, 'businessId' => 'b', 'version' => 0, 'action' => 'save', 'value' => f7value(), 'status' => 'paid'])));
    expect(CampaignRules::action()->parse(f7json(['id' => F7_CID, 'businessId' => 'b', 'version' => 0, 'action' => 'save', 'value' => f7value(['headline' => '  Periyodik bakım  '])]))['value']['headline'])->toBe('Periyodik bakım');
});

it('only exact verified owner membership authorizes campaign writes', function () {
    $m = ['userId' => 'u', 'businessId' => 'test-business', 'role' => 'owner', 'active' => true];
    expect(CampaignRules::owns('u', 'test-business', f7business(), $m))->toBeTrue();
    foreach ([['role' => 'staff'], ['userId' => 'x'], ['businessId' => 'x'], ['active' => false]] as $bad) {
        expect(CampaignRules::owns('u', 'test-business', f7business(), array_merge($m, $bad)))->toBeFalse();
    }
    expect(CampaignRules::owns('u', 'test-business', f7business(['ownership' => 'unclaimed']), $m))->toBeFalse();
    expect(CampaignRules::owns('u', 'test-business', f7business(['sample' => true]), $m))->toBeFalse();
    expect(CampaignRules::owns('u', 'test-business', f7business(), null))->toBeFalse();
});

it('submission requires published verified matching location/category and valid schedule', function () {
    expect(CampaignRules::eligible(f7business(), f7value(), f7now()))->toBeTrue();
    foreach ([['published' => false], ['sample' => true], ['coordinateStatus' => 'unverified'], ['latitude' => NAN], ['city' => 'Ankara'], ['categories' => ['tyres']]] as $bad) {
        f7rejects(fn () => CampaignRules::transition(f7saved(), ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 1, 'action' => 'submit'], f7business($bad), ['owner' => true, 'admin' => false, 'affiliated' => true], f7now()));
    }
    foreach (['2026-10-18', '2026-10-19', '2027-12-01'] as $startDate) {
        expect(CampaignRules::eligible(f7business(), f7value(['startDate' => $startDate]), f7now()))->toBeFalse();
    }
});

it('approval waits for payment and never produces an active or paid campaign', function () {
    $result = CampaignRules::transition(f7pending(), ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 2, 'action' => 'approve', 'reason' => 'Hedef ve içerik uygun bulundu.'], f7business(['profileVersion' => 4]), ['owner' => false, 'admin' => true, 'affiliated' => false], f7now());
    expect($result['status'])->toBe('approved')->and($result['version'])->toBe(3)->and($result['approvedProfileVersion'])->toBe(4);
    expect($result)->not->toHaveKey('paid')->not->toHaveKey('amount');
    f7rejects(fn () => CampaignRules::transition($result, ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 3, 'action' => 'save', 'value' => f7value()], f7business(), ['owner' => true, 'admin' => false, 'affiliated' => true], f7now()));
    expect(CampaignRules::view(F7_CID, $result))->not->toBeNull();
});

it('self review, stale commands, cross business actions and invalid state fail', function () {
    $owner = ['owner' => true, 'admin' => false, 'affiliated' => true];
    f7rejects(fn () => CampaignRules::transition(f7pending(), ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 2, 'action' => 'approve', 'reason' => 'Uygun bulundu.'], f7business(), ['owner' => false, 'admin' => true, 'affiliated' => true], f7now()));
    f7rejects(fn () => CampaignRules::transition(f7saved(), ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 0, 'action' => 'submit'], f7business(), $owner, f7now()));
    f7rejects(fn () => CampaignRules::transition(f7saved(), ['id' => F7_CID, 'businessId' => 'other', 'version' => 1, 'action' => 'submit'], f7business(), $owner, f7now()));
    f7rejects(fn () => CampaignRules::transition(f7saved(), ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 1, 'action' => 'submit'], f7business(), ['owner' => false, 'admin' => true, 'affiliated' => false], f7now()));
    f7rejects(fn () => CampaignRules::transition(null, ['id' => F7_CID, 'businessId' => 'test-business', 'version' => 0, 'action' => 'cancel'], f7business(), $owner, f7now()));
});

it('campaign http input matches the source query rules', function () {
    $ok = fn (string $m, string $q, ?string $t = null) => CampaignRules::http($m, Query::parse($q), $t);
    $ok('GET', 'businessId=b1');
    $ok('GET', 'businessId=b1&scope=owner&id='.F7_CID);
    $ok('GET', 'queue=true&status=pending');
    $ok('PATCH', '', 'application/json; charset=utf-8');
    foreach (['businessId=b1&businessId=b2', 'businessId=b1&scope=other', 'queue=true&status=paid', 'businessId=b1&scope=owner&id='.F7_CID.'&after='.F7_CID, 'businessId=../x', 'x=1'] as $q) {
        f7rejects(fn () => $ok('GET', $q));
    }
    try {
        $ok('PATCH', '', 'text/plain');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('campaign_content_type');
    }
    f7rejects(fn () => $ok('PATCH', 'x=1', 'application/json'));
});

it('ad price is computed server side in TRY minor units with Istanbul dates', function () {
    $c = CampaignRules::view(F7_CID, ['businessId' => 'b', 'status' => 'approved', 'version' => 3, 'approvedProfileVersion' => 0] + f7value());
    $t = ['placement' => 'search', 'city' => 'İstanbul', 'category' => 'mechanical', 'enabled' => true, 'capacity' => 1, 'sevenDaysMinor' => 10001, 'thirtyDaysMinor' => 30000, 'taxBasisPoints' => 1234, 'version' => 1];
    $p = AdRules::price($c, $t, f7now());
    expect($p['subtotalMinor'])->toBe(10001)->and($p['taxMinor'])->toBe(1234)->and($p['totalMinor'])->toBe(11235)->and($p['currency'])->toBe('TRY');
    expect($p['dates'])->toBe(['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26']);
    expect($p['expiresAtMs'])->toBe(f7now() + 15 * 60000);
    expect(AdRules::price($c, $t, F7::trStart('2026-10-20') - 100)['expiresAtMs'])->toBe(F7::trStart('2026-10-20'));
    foreach ([['enabled' => false], ['city' => 'Ankara'], ['version' => 0], ['sevenDaysMinor' => 0], ['taxBasisPoints' => 10001]] as $bad) {
        f7rejects(fn () => AdRules::price($c, array_merge($t, $bad), f7now()));
    }
    f7rejects(fn () => AdRules::price($c, $t, F7::trStart('2026-10-20')));
    expect(AdRules::marketId(['placement' => 'search', 'city' => 'İstanbul', 'category' => 'mechanical']))->toBe(hash('sha256', '["search","İstanbul","mechanical"]'));
});

it('inventory allocation enforces capacity and ignores expired holds', function () {
    $now = f7now();
    $other = '00000000-0000-4000-8000-000000000012';
    expect(AdRules::allocate(null, 1, F7_RID, $now + 1000, $now))->toBe([['reservationId' => F7_RID, 'expiresAtMs' => $now + 1000]]);
    f7rejects(fn () => AdRules::allocate([['reservationId' => $other, 'expiresAtMs' => $now + 1]], 1, F7_RID, $now + 1000, $now));
    expect(AdRules::allocate([['reservationId' => $other, 'expiresAtMs' => $now]], 1, F7_RID, $now + 1000, $now))->toHaveCount(1);
    expect(AdRules::allocate([['reservationId' => F7_RID, 'expiresAtMs' => $now + 5]], 1, F7_RID, $now + 1000, $now))->toBe([['reservationId' => F7_RID, 'expiresAtMs' => $now + 5]]);
    f7rejects(fn () => AdRules::holds(array_fill(0, 21, ['reservationId' => $other, 'expiresAtMs' => $now + 1]), $now));
    f7rejects(fn () => AdRules::holds([['reservationId' => 'x', 'expiresAtMs' => 1]], $now));
    f7rejects(fn () => AdRules::holds('bad', $now));
});

it('metric tokens are sealed, bounded and single-purpose', function () {
    $secret = str_repeat('ab', 32);
    $now = f7now();
    $token = AdMetrics::token(F7_CID, 'b', $now + 3600000, $secret, $now);
    $v = AdMetrics::read($token, $secret, $now + 1000);
    expect($v['campaignId'])->toBe(F7_CID)->and($v['expiresAt'])->toBe($now + 600000);
    f7rejects(fn () => AdMetrics::read($token, str_repeat('cd', 32), $now));
    f7rejects(fn () => AdMetrics::read($token, $secret, $now + 600000));
    f7rejects(fn () => AdMetrics::read(substr($token, 0, -2).'AA', $secret, $now));
    f7rejects(fn () => AdMetrics::token(F7_CID, 'b', $now, $secret, $now));
    f7rejects(fn () => AdMetrics::token(F7_CID, 'b', $now + 1000, 'short', $now));
    $dates = AdRules::metricDates($now);
    expect($dates)->toHaveCount(30)->and($dates[29])->toBe('2026-10-19')->and($dates[0])->toBe('2026-09-20');
    $rows = array_map(fn (string $d): array => ['date' => $d, 'views' => $d === '2026-10-19' ? 3 : 0, 'clicks' => $d === '2026-10-19' ? 1 : 0], $dates);
    expect(AdRules::summary($rows)['ctr'])->toBe(33.33);
    expect(AdRules::summary(array_map(fn (string $d): array => ['date' => $d, 'views' => 0, 'clicks' => 0], $dates))['ctr'])->toBeNull();
    $m = OwnerAdFinance::metrics(['campaignId' => F7_CID, 'businessId' => 'b', 'campaignVersion' => 1], AdRules::summary($rows), false);
    expect($m['views'])->toBe(3);
});

it('brand quote uses inclusive calendar days and integer minor-unit tax rounding', function () {
    $market = ['placement' => 'home_banner', 'city' => 'İstanbul', 'category' => ''];
    $c = ['creative' => $market + ['brand' => 'Test Marka', 'headline' => 'Test marka başlığı', 'description' => 'Test ürün açıklaması burada.', 'url' => 'https://example.com', 'startDate' => '2026-10-20', 'endDate' => '2026-10-26'], 'status' => 'draft', 'version' => 1, 'note' => '', 'lastEditorId' => 'editor', 'createdAtMs' => f7now(), 'updatedAtMs' => f7now()];
    $t = ['market' => $market, 'enabled' => true, 'dailyMinor' => 10001, 'taxBasisPoints' => 1750, 'version' => 2];
    $input = ['campaignId' => F7_CID, 'campaignVersion' => 1, 'tariffVersion' => 2];
    $q = BrandRules::price($c, $t, $input, f7now());
    expect([$q['days'], $q['subtotalMinor'], $q['taxMinor'], $q['totalMinor'], $q['expiresAtMs'], $q['reservation'], $q['payable'], $q['kind']])->toBe([7, 70007, 12251, 82258, f7now() + 900000, false, false, 'estimate']);
    expect($q)->not->toHaveKey('lastEditorId');
    foreach ([['version' => 3], ['enabled' => false], ['market' => ['city' => ''] + $market], ['market' => ['category' => 'mechanical'] + $market]] as $bad) {
        f7rejects(fn () => BrandRules::price($c, array_merge($t, $bad), $input, f7now()));
    }
    f7rejects(fn () => BrandRules::price(['version' => 2] + $c, $t, $input, f7now()));
    expect(BrandRules::marketKey($market))->not->toBe(BrandRules::marketKey(['city' => ''] + $market));
    $start = F7::trStart('2026-10-20');
    expect(BrandRules::price($c, $t, $input, $start - 100)['expiresAtMs'])->toBe($start);
    f7rejects(fn () => BrandRules::price($c, $t, $input, $start));
    f7rejects(fn () => BrandRules::price($c, $t, $input, $start - 181 * F7::DAY));
    f7rejects(fn () => BrandRules::quoteInput()->parse(f7json($input + ['totalMinor' => 1])));
});

it('brand creative rejects unsafe links and invalid dates; review needs a different editor', function () {
    $creative = ['brand' => 'Test Marka', 'headline' => 'Test marka başlığı', 'description' => 'Test ürün açıklaması burada.', 'url' => 'https://example.com/x', 'placement' => 'home_banner', 'city' => '', 'category' => '', 'startDate' => '2026-10-20', 'endDate' => '2026-10-26'];
    expect(BrandRules::creative()->parse(f7json($creative))['url'])->toBe('https://example.com/x');
    foreach (['http://example.com', 'https://user:p@example.com', 'https://example.com:8443', 'https://localhost', 'https://a.local', 'javascript:alert(1)'] as $url) {
        f7rejects(fn () => BrandRules::creative()->parse(f7json(['url' => $url] + $creative)));
    }
    f7rejects(fn () => BrandRules::creative()->parse(f7json(['endDate' => '2027-02-01'] + $creative)));
    f7rejects(fn () => BrandRules::creative()->parse(f7json(['placement' => 'category_banner'] + $creative)));
    f7rejects(fn () => BrandRules::action()->parse(f7json(['id' => F7_CID, 'version' => 0, 'action' => 'save', 'value' => $creative, 'actorId' => 'x'])));
    $draft = BrandRules::transition(null, ['id' => F7_CID, 'version' => 0, 'action' => 'save', 'value' => $creative], 'editor', f7now());
    $pending = BrandRules::transition($draft, ['id' => F7_CID, 'version' => 1, 'action' => 'submit'], 'editor', f7now());
    $review = ['id' => F7_CID, 'version' => 2, 'action' => 'approve', 'note' => 'Hak ve içerik kontrol edildi.', 'rightsChecked' => true];
    f7rejects(fn () => BrandRules::transition($pending, $review, 'editor', f7now()));
    f7rejects(fn () => BrandRules::transition($pending, ['rightsChecked' => false] + $review, 'reviewer', f7now()));
    $approved = BrandRules::transition($pending, $review, 'reviewer', f7now());
    expect($approved['status'])->toBe('approved');
    f7rejects(fn () => BrandRules::transition($approved, ['id' => F7_CID, 'version' => 3, 'action' => 'save', 'value' => $creative], 'editor', f7now()));
    $view = BrandRules::view(F7_CID, $pending, 'reviewer');
    expect($view['canReview'])->toBeTrue()->and($view)->not->toHaveKey('lastEditorId');
    expect(BrandRules::view(F7_CID, $pending, 'editor')['canReview'])->toBeFalse();
});

it('payment case and brand review decisions are versioned and change state', function () {
    $id = str_repeat('a', 64);
    $patch = PaymentCases::change(['status' => 'open'], ['id' => $id, 'version' => 0, 'status' => 'reviewing', 'note' => 'İnceleme başlatıldı.'], 5);
    expect($patch)->toBe(['status' => 'reviewing', 'version' => 1, 'note' => 'İnceleme başlatıldı.', 'updatedAtMs' => 5]);
    f7rejects(fn () => PaymentCases::change(['status' => 'open'], ['id' => $id, 'version' => 1, 'status' => 'reviewing', 'note' => 'x'], 5));
    f7rejects(fn () => PaymentCases::change(['status' => 'open'], ['id' => $id, 'version' => 0, 'status' => 'open', 'note' => 'x'], 5));
    expect(PaymentCases::view($id, ['reason' => 'bogus'])['reason'])->toBe('unknown');
    expect(PaymentCases::amounts(['totalMinor' => 100, 'currency' => 'TRY'], ['amountMinor' => 90, 'currency' => 'try']))->toBe(['expected' => ['minor' => 100, 'currency' => 'TRY'], 'received' => null]);
    f7rejects(fn () => BrandReview::change(['status' => 'weird'], f7json(['id' => $id, 'version' => 0, 'status' => 'closed', 'note' => 'Kapatıldı, kontrol edildi.']), 1));
    expect(BrandReview::query()->parse(f7json(['caseId' => $id, 'before' => '3'])))->toBe(['caseId' => $id, 'before' => 3]);
    f7rejects(fn () => BrandReview::query()->parse(f7json(['after' => $id, 'recordId' => F7_CID])));
    $v = BrandReview::view($id, ['recordId' => F7_RID, 'receiptId' => $id, 'status' => 'open', 'reason' => 'expired', 'createdAtMs' => 1], ['reservationId' => F7_RID, 'totalMinor' => 5, 'currency' => 'TRY'], ['recordId' => F7_RID, 'amountMinor' => 5, 'currency' => 'TRY'], ['status' => 'needs_review', 'checkedAtMs' => 2]);
    expect($v['expected']['minor'])->toBe(5)->and($v['received']['minor'])->toBe(5)->and($v['payment']['status'])->toBe('needs_review');
});

it('cancellation and refund review inputs fail closed', function () {
    f7rejects(fn () => BrandCancellation::request()->parse(f7json(['reservationId' => F7_RID, 'reason' => 'kısa'])));
    f7rejects(fn () => BrandCancellation::decision()->parse(f7json(['reservationId' => F7_RID, 'version' => 0, 'decision' => 'approve', 'note' => 'Uygun bulundu, onay.'])));
    expect(BrandCancellation::queueQuery()->parse(new stdClass)['filter'])->toBe('pending');
    f7rejects(fn () => BrandCancellation::historyQuery()->parse(f7json(['reservationId' => F7_RID, 'after' => 'x_y'])));
    $action = ['reservationId' => F7_RID, 'eventId' => 'evt-1', 'version' => 0, 'status' => 'closed', 'note' => 'İnceleme tamamlandı.'];
    f7rejects(fn () => BrandRefundReview::action()->parse(f7json($action)));
    expect(BrandRefundReview::action()->parse(f7json($action + ['acknowledged' => true]))['acknowledged'])->toBeTrue();
    expect(BrandRefundReview::query()->parse(f7json(['reservationId' => F7_RID, 'eventId' => 'evt-1', 'before' => '21']))['before'])->toBe(21);
    f7rejects(fn () => BrandRefundReview::query()->parse(f7json(['reservationId' => F7_RID, 'eventId' => 'evt-1', 'before' => '0'])));
});

it('gateway redirect is HTTPS, allowlisted and bounded to the held-price expiry', function () {
    $order = ['kind' => 'business', 'orderId' => F7_RID, 'currency' => 'TRY', 'amountMinor' => 1010, 'expiresAtMs' => 2000, 'idempotencyKey' => str_repeat('a', 64)];
    $origins = ['https://checkout.example.test'];
    expect(Checkout::validateRedirect(f7json(['sessionId' => 's', 'url' => 'https://checkout.example.test/pay?x=1', 'expiresAtMs' => 1500]), $origins, 1000, $order)['sessionId'])->toBe('s');
    foreach (['http://checkout.example.test/pay', 'https://evil.test/pay', 'https://u:p@checkout.example.test/', 'https://checkout.example.test:444/', 'https://checkout.example.test/#x'] as $url) {
        f7rejects(fn () => Checkout::validateRedirect(f7json(['sessionId' => 's', 'url' => $url, 'expiresAtMs' => 1500]), $origins, 1000, $order));
    }
    f7rejects(fn () => Checkout::validateRedirect(f7json(['sessionId' => 's', 'url' => 'https://checkout.example.test/', 'expiresAtMs' => 2001]), $origins, 1000, $order));
    f7rejects(fn () => Checkout::validateRedirect(f7json(['sessionId' => 's', 'url' => 'https://checkout.example.test/', 'expiresAtMs' => 1000]), $origins, 1000, $order));
    f7rejects(fn () => Checkout::validateRedirect(f7json(['sessionId' => 's', 'url' => 'https://checkout.example.test/', 'expiresAtMs' => 1500, 'card' => '4111']), $origins, 1000, $order));
    f7rejects(fn () => Checkout::command()->parse(f7json(['kind' => 'business', 'reservationId' => F7_RID, 'amountMinor' => 1])));
    expect(PaymentGateway::httpsOrigin('https://pay.example.com'))->toBe('https://pay.example.com');
    foreach (['http://pay.example.com', 'https://127.0.0.1', 'https://pay.example.com/x', 'https://pay.example.com:8443', 'https://10.0.0.1'] as $bad) {
        f7rejects(fn () => PaymentGateway::httpsOrigin($bad));
    }
});

it('webhook authenticates exact raw bytes, timestamp window and constant-time HMAC before decoding', function () {
    $secret = str_repeat('s', 40);
    $now = 1_800_000_000_000;
    $ts = (string) intdiv($now, 1000);
    $raw = '{"eventId":"evt_1","kind":"business","orderId":"'.F7_RID.'","paymentId":"p1","type":"payment.updated"}';
    $sig = 'v1='.hash_hmac('sha256', $ts.'.'.$raw, $secret);
    expect(PaymentGateway::verifyWebhook($raw, $ts, $sig, $secret, $now)['eventId'])->toBe('evt_1');
    f7rejects(fn () => PaymentGateway::verifyWebhook($raw.' ', $ts, $sig, $secret, $now));
    f7rejects(fn () => PaymentGateway::verifyWebhook($raw, $ts, $sig, $secret, $now + 301000));
    f7rejects(fn () => PaymentGateway::verifyWebhook($raw, $ts, strtoupper($sig), $secret, $now));
    f7rejects(fn () => PaymentGateway::verifyWebhook($raw, $ts, $sig, str_repeat('t', 40), $now));
    f7rejects(fn () => PaymentGateway::verifyWebhook($raw, $ts, $sig, 'short', $now));
    $extra = str_replace('"type"', '"amountMinor":1,"type"', $raw);
    f7rejects(fn () => PaymentGateway::verifyWebhook($extra, $ts, 'v1='.hash_hmac('sha256', $ts.'.'.$extra, $secret), $secret, $now));
    f7rejects(fn () => PaymentGateway::verifyWebhook(str_repeat(' ', 20001), $ts, $sig, $secret, $now));
});

it('brand media and metric tokens expire within two minutes and bind the image', function () {
    $secret = str_repeat('0f', 32);
    $now = f7now();
    $q = ['placement' => 'home_banner', 'city' => 'İstanbul', 'category' => '', 'reservationId' => F7_RID, 'imageId' => F7_CID];
    $access = BrandServing::issueMediaToken($q, $now + 3600000, $secret, $now);
    expect($access['expiresAtMs'])->toBe($now + 120000);
    $v = BrandServing::readMediaToken($access['token'], $secret, $now + 1);
    expect($v['imageId'])->toBe(F7_CID)->and($v['v'])->toBe(1);
    f7rejects(fn () => BrandServing::readMetric($access['token'], $secret, $now + 1));
    f7rejects(fn () => BrandServing::readMediaToken($access['token'], $secret, $now + 120000));
    expect(BrandServing::issueMediaToken($q, $now + 5, $secret, $now)['expiresAtMs'])->toBe($now + 5);
    f7rejects(fn () => BrandServing::issueMetric($q, $now, $secret, $now));
    expect(BrandServing::rotate(['c', 'a', 'b', 'a'], 60000))->toBe(['b', 'c', 'a']);
});

it('brand media cleanup keeps current images and fails closed', function () {
    $now = f7now();
    $id = F7_RID;
    $row = ['campaignId' => F7_CID, 'status' => 'attached', 'createdAtMs' => $now - 10];
    expect(BrandMediaCleanup::decision($id, $row, ['asset' => ['id' => $id]], $now))->toBe('keep');
    expect(BrandMediaCleanup::decision($id, $row + ['retentionHold' => true], null, $now))->toBe('keep');
    expect(BrandMediaCleanup::decision($id, $row, ['asset' => ['id' => F7_CID]], $now))->toBe('observe');
    expect(BrandMediaCleanup::decision($id, $row + ['orphanObservedAtMs' => $now - 30 * F7::DAY], null, $now))->toBe('keep');
    expect(BrandMediaCleanup::decision($id, ['createdAtMs' => $now - 31 * F7::DAY] + $row + ['orphanObservedAtMs' => $now - 30 * F7::DAY], null, $now))->toBe('purge');
    expect(BrandMediaCleanup::decision($id, ['status' => 'uploading'] + $row, null, $now))->toBe('keep');
    expect(BrandMediaCleanup::decision($id, ['status' => 'failed', 'createdAtMs' => $now - F7::DAY] + $row, null, $now))->toBe('purge');
    expect(BrandMediaCleanup::decision($id, ['status' => 'purging'] + $row, null, $now))->toBe('purge');
    expect(BrandMediaCleanup::decision('x', $row, null, $now))->toBe('keep');
    expect(BrandMediaCleanup::decision($id, ['createdAtMs' => $now + 1] + $row, null, $now))->toBe('keep');
    expect(BrandMediaCleanup::decision($id, ['status' => 'purged'] + $row, null, $now))->toBe('keep');
});

it('owner finance projection refuses inconsistent bindings', function () {
    $base = ['campaignId' => F7_CID, 'businessId' => 'b', 'campaignVersion' => 2, 'status' => 'approved', 'checkedAtMs' => 1, 'reservationsEnabled' => false, 'checkoutEnabled' => false, 'quote' => null, 'reservation' => null, 'payment' => null];
    expect(OwnerAdFinance::check($base))->toBe($base);
    f7rejects(fn () => OwnerAdFinance::check(['checkoutEnabled' => true] + $base));
    f7rejects(fn () => OwnerAdFinance::check(['payment' => ['status' => 'paid', 'currency' => 'TRY', 'totalMinor' => 5, 'checkedAtMs' => null]] + $base));
    expect(fn () => OwnerAdFinance::http('GET', Query::parse('campaignId='.F7_CID.'&businessId=b&campaignVersion=1e1'), null))->toThrow(RuntimeException::class);
    expect(OwnerAdFinance::http('GET', Query::parse('campaignId='.F7_CID.'&businessId=b&campaignVersion=2'), null)['campaignVersion'])->toBe(2);
    expect(OwnerAdFinance::http('POST', Query::parse(''), 'application/json'))->toBeNull();
    expect(fn () => OwnerAdFinance::http('POST', Query::parse(''), 'text/plain'))->toThrow(RuntimeException::class, 'ad_content_type');
});

it('F7 helpers follow JS date and rounding rules', function () {
    expect(F7::trStart('2026-02-30'))->toBeNull();
    expect(F7::trDate(F7::trStart('2026-10-20')))->toBe('2026-10-20');
    expect(F7::trDate(F7::trStart('2026-10-20') - 1))->toBe('2026-10-19');
    expect(F7::round(1234.5))->toBe(1235)->and(F7::round(1234.4999))->toBe(1234);
    expect(F7::num(100.0))->toBe(100);
    expect(fn () => throw new ZodError('custom', 'Invalid input'))->toThrow(ZodError::class);
});
