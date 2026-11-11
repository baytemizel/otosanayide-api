<?php

namespace App\Domain\Ads;

use App\Domain\Payments\F7;
use App\Support\Query;
use App\Support\Tx;
use App\Support\Z;
use App\Support\ZodError;
use RuntimeException;

/** packages/server/owner-ad-finance.ts ve core/owner-ad-finance.ts: salt okunur sahip projeksiyonu. */
final class OwnerAdFinance
{
    /** adFinanceHttp: GET sorgusunu dondurur, digerlerinde null. */
    public static function http(string $method, Query $q, ?string $type): ?array
    {
        if ($method === 'GET') {
            foreach ($q->keys() as $k) {
                if (! in_array($k, ['businessId', 'campaignId', 'campaignVersion'], true) || $q->count($k) !== 1) {
                    throw new RuntimeException('ad_query');
                }
            }
            $v = $q->get('campaignVersion');
            if ($v === null || $v === '' || preg_match('/^\d+$/D', $v) !== 1) {
                throw new RuntimeException('ad_query');
            }
            $n = (float) $v;

            return AdRules::financeQuery()->parse((object) ['campaignId' => $q->get('campaignId'), 'businessId' => $q->get('businessId'), 'campaignVersion' => F7::num($n)]);
        }
        if ($q->keys() !== []) {
            throw new RuntimeException('ad_query');
        }
        if (preg_match('/^application\/json(?:\s*;|$)/iD', $type ?? '') !== 1) {
            throw new RuntimeException('ad_content_type');
        }

        return null;
    }

    /** ownerAdFinance.parse superRefine */
    public static function check(array $v): array
    {
        $bad = static fn () => throw new ZodError('custom', 'Invalid input');
        if ($v['checkoutEnabled'] && (! $v['reservationsEnabled'] || $v['status'] !== 'approved')) {
            $bad();
        }
        $q = $v['quote'];
        $r = $v['reservation'];
        $p = $v['payment'];
        if (($q !== null && ($v['status'] !== 'approved' || $q['campaignVersion'] !== $v['campaignVersion'])) || ($r !== null && $r['campaignVersion'] > $v['campaignVersion']) || ($p !== null && ($r === null || $p['totalMinor'] !== $r['totalMinor']))) {
            $bad();
        }
        if ($q !== null && ($q['totalMinor'] !== $q['subtotalMinor'] + $q['taxMinor'] || ! in_array(count($q['dates']), [7, 30], true) || $q['dates'] !== CampaignRules::dates($q['dates'][0], count($q['dates'])))) {
            $bad();
        }
        if ($r !== null && $r['totalMinor'] !== $r['subtotalMinor'] + $r['taxMinor']) {
            $bad();
        }
        if ($p !== null && ! in_array($p['status'], ['pending', 'failed', 'paid', 'needs_review'], true)) {
            $bad();
        }

        return $v;
    }

    /** @param array{campaignId:string,businessId:string,campaignVersion:int} $input */
    public static function read(string $uid, array $input, bool $enabled, ?int $now = null, bool $checkoutEnabled = false): array
    {
        $now ??= F7::nowMs();
        AdRules::financeQuery()->parse((object) $input);

        return Tx::run(static function () use ($uid, $input, $enabled, $now, $checkoutEnabled): array {
            $raw = AdStore::campaign($input['campaignId'], true);
            ['b' => $b, 'm' => $m] = AdStore::business($uid, $input['businessId']);
            $campaign = $raw === null ? null : CampaignRules::view($input['campaignId'], $raw);
            if ($campaign === null || $campaign['businessId'] !== $input['businessId'] || ! CampaignRules::owns($uid, $input['businessId'], $b, $m)) {
                throw new AdInventoryError('access');
            }
            if ($campaign['version'] !== $input['campaignVersion']) {
                throw new AdInventoryError('version');
            }
            [$hasAnchor, $anchor] = AdStore::anchor($input['campaignId']);
            $reservation = null;
            $payment = null;
            if ($hasAnchor) {
                $ref = $anchor['reservationId'];
                if (preg_match('/^[-a-f0-9]{36}$/iD', $ref) !== 1) {
                    throw new AdInventoryError('inventory');
                }
                $r = AdStore::reservation($ref);
                if ($r === null) {
                    throw new AdInventoryError('inventory');
                }
                if ($r['userId'] === $uid) {
                    if (! in_array($r['status'], ['held', 'released', 'paid'], true) || $r['campaignId'] !== $input['campaignId'] || $r['businessId'] !== $input['businessId']) {
                        throw new AdInventoryError('inventory');
                    }
                    $reservation = AdRules::reservationView($ref, $r, $now);
                    $o = AdStore::order($ref);
                    if ($o !== null) {
                        if ($o['userId'] !== $uid || $o['reservationId'] !== $ref || $o['campaignId'] !== $input['campaignId'] || $o['businessId'] !== $input['businessId'] || $o['totalMinor'] !== $r['totalMinor'] || $o['currency'] !== $r['currency'] || $o['campaignVersion'] !== $r['campaignVersion']) {
                            throw new AdInventoryError('inventory');
                        }
                        $payment = ['status' => $o['status'], 'currency' => $o['currency'], 'totalMinor' => $o['totalMinor'], 'checkedAtMs' => $o['paidAtMs'] ?? $o['checkedAtMs'] ?? null];
                    }
                }
            }
            $quote = null;
            if ($campaign['status'] === 'approved' && CampaignRules::eligible($b, $campaign, $now) && ($campaign['approvedProfileVersion'] ?? null) === ($b['profileVersion'] ?? 0)) {
                $t = AdStore::tariff(AdRules::marketId($campaign), true);
                $subset = $t === null ? [] : array_intersect_key($t, array_flip(AdRules::TARIFF_FIELDS));
                $parsed = AdRules::parseOk(AdRules::tariff(), (object) $subset);
                if ($parsed !== null && $parsed['enabled'] && F7::safeInt($t['version']) && $t['version'] > 0) {
                    $quote = AdRules::price($campaign, $parsed + ['version' => $t['version']], $now);
                }
            }

            return self::check($input + ['status' => $campaign['status'], 'checkedAtMs' => $now, 'reservationsEnabled' => $enabled, 'checkoutEnabled' => $checkoutEnabled && $enabled && $campaign['status'] === 'approved', 'quote' => $quote, 'reservation' => $reservation, 'payment' => $payment]);
        });
    }

    /** ownerAdMetrics.parse superRefine */
    public static function metrics(array $query, array $report, bool $enabled): array
    {
        $v = $query + ['enabled' => $enabled] + $report;
        $sum = AdRules::summary($report['rows']);
        $first = F7::utcStart($report['rows'][0]['date'] ?? '') ?? 0;
        foreach ($report['rows'] as $i => $row) {
            if ($row['date'] !== F7::isoDate($first + $i * F7::DAY)) {
                throw new ZodError('custom', 'Invalid input');
            }
        }
        if (count($report['rows']) !== 30 || $v['views'] !== $sum['views'] || $v['clicks'] !== $sum['clicks'] || $v['ctr'] !== $sum['ctr']) {
            throw new ZodError('custom', 'Invalid input');
        }
        Z::boolean()->parse($enabled);

        return ['campaignId' => $query['campaignId'], 'businessId' => $query['businessId'], 'campaignVersion' => $query['campaignVersion'], 'enabled' => $enabled, 'rows' => $report['rows'], 'views' => $report['views'], 'clicks' => $report['clicks'], 'ctr' => $report['ctr']];
    }
}
