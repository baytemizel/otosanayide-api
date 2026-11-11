<?php

use App\Domain\Ads\AdRules;
use App\Domain\Ads\CampaignRules;
use App\Domain\Ads\OwnerAdFinance;
use App\Domain\Payments\Checkout;
use App\Domain\Payments\F7;

// F7 DTO'lari veritabani olmadan uretim kurucularindan gelir; sekli packages/core ayristiricilari denetler.

it('writes F7 contract fixtures from production DTO builders', function () {
    $cid = '00000000-0000-4000-8000-000000000001';
    $rid = '00000000-0000-4000-8000-000000000011';
    $now = F7::trStart('2026-10-19') + 10 * 3600000;
    $business = ['name' => 'Sentetik Oto', 'ownership' => 'verified', 'published' => true, 'coordinateStatus' => 'verified', 'latitude' => 41.0, 'longitude' => 29.0, 'city' => 'İstanbul', 'categories' => ['mechanical', 'bogus']];
    $value = ['placement' => 'search', 'city' => 'İstanbul', 'category' => 'mechanical', 'days' => 7, 'startDate' => '2026-10-20', 'headline' => 'Sentetik kampanya', 'description' => 'Sentetik hizmet açıklaması.'];
    $draft = CampaignRules::transition(null, ['id' => $cid, 'businessId' => 'b1', 'version' => 0, 'action' => 'save', 'value' => $value], $business, ['owner' => true, 'admin' => false, 'affiliated' => true], $now);
    $item = CampaignRules::view($cid, $draft);
    contract('owner-campaign-page', 'owner-campaign-page', ['business' => CampaignRules::branch('b1', $business), 'items' => [$item], 'next' => null]);
    contract('owner-campaign-item', 'owner-campaign-item', ['item' => $item]);

    $approved = CampaignRules::view($cid, ['status' => 'approved', 'version' => 3, 'approvedProfileVersion' => 0] + $draft);
    $tariff = ['placement' => 'search', 'city' => 'İstanbul', 'category' => 'mechanical', 'enabled' => true, 'capacity' => 2, 'sevenDaysMinor' => 10001, 'thirtyDaysMinor' => 30000, 'taxBasisPoints' => 1234, 'version' => 1];
    $price = AdRules::price($approved, $tariff, $now);
    $reservation = AdRules::reservationView($rid, $price + ['status' => 'held'], $now);
    $finance = OwnerAdFinance::check(['campaignId' => $cid, 'businessId' => 'b1', 'campaignVersion' => 3, 'status' => 'approved', 'checkedAtMs' => $now, 'reservationsEnabled' => true, 'checkoutEnabled' => false, 'quote' => $price, 'reservation' => $reservation, 'payment' => ['status' => 'pending', 'currency' => 'TRY', 'totalMinor' => $reservation['totalMinor'], 'checkedAtMs' => null]]);
    contract('owner-ad-finance', 'owner-ad-finance', $finance);
    contract('owner-ad-finance-empty', 'owner-ad-finance', OwnerAdFinance::check(['campaignId' => $cid, 'businessId' => 'b1', 'campaignVersion' => 1, 'status' => 'draft', 'checkedAtMs' => $now, 'reservationsEnabled' => false, 'checkoutEnabled' => false, 'quote' => null, 'reservation' => null, 'payment' => null]));
    contract('owner-ad-reserve', 'owner-ad-reserve', ['campaignId' => $cid, 'businessId' => 'b1', 'campaignVersion' => 3, 'reservation' => $reservation]);
    $rows = array_map(fn (string $d): array => ['date' => $d, 'views' => 4, 'clicks' => $d === '2026-10-19' ? 1 : 0], AdRules::metricDates($now));
    contract('owner-ad-metrics', 'owner-ad-metrics', OwnerAdFinance::metrics(['campaignId' => $cid, 'businessId' => 'b1', 'campaignVersion' => 3], AdRules::summary($rows), false));

    $order = ['kind' => 'business', 'orderId' => $rid, 'currency' => 'TRY', 'amountMinor' => $price['totalMinor'], 'expiresAtMs' => $now + 600000, 'idempotencyKey' => str_repeat('a', 64)];
    $session = Checkout::validateRedirect((object) ['sessionId' => 'sess_1', 'url' => 'https://checkout.example.test/pay/sess_1', 'expiresAtMs' => $now + 500000], ['https://checkout.example.test'], $now, $order);
    contract('checkout-receipt', 'checkout-receipt', ['kind' => 'business', 'orderId' => $rid, 'session' => $session]);

    expect(file_exists(storage_path('contract/owner-ad-finance.json')))->toBeTrue();
});
