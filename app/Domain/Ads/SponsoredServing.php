<?php

namespace App\Domain\Ads;

use App\Domain\Brand\BrandDelivery;
use App\Domain\Catalogue\BusinessReader;
use App\Domain\Catalogue\SearchFilter;
use App\Domain\Catalogue\SearchService;
use App\Domain\Catalogue\Taxonomy;
use App\Domain\Content\Docs;
use App\Domain\Content\Policy;
use App\Domain\Content\Rows;
use App\Domain\Payments\F7;
use App\Support\Tx;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * packages/server/sponsored.ts serveSponsored + packages/core/sponsored.ts (adPlacement, rotateCandidates, sponsoredEligible).
 * Salt okunur tutarli okuma; sayac veya katalog taramasi yok. Yalniz AD_SERVING_ENABLED=true iken cagrilir.
 * Cikti kaynak sozlesmesi: {business, headline, description, expiresAtMs, metricToken?}; en cok 3, isletme basina tek.
 * Sponsorlu kayit organik `items` listesine karismaz, istemci "Sponsorlu" etiketiyle ayri gosterir.
 */
final class SponsoredServing
{
    public static function placement(SearchFilter $f): string
    {
        return $f->q !== '' || $f->district !== '' || $f->service !== '' || $f->brand !== '' || $f->card || $f->center !== null ? 'search' : ($f->category !== '' ? 'category' : 'home');
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function rotate(array $items, int $now, int $limit = 6): array
    {
        if ($items === []) {
            return [];
        }
        $start = intdiv($now, 60000) % count($items);
        $out = [];
        for ($i = 0; $i < min($limit, count($items)); $i++) {
            $out[] = $items[($start + $i) % count($items)];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function serve(SearchFilter $f, SearchService $search, ?int $now = null): array
    {
        if ($f->city === '') {
            return [];
        }
        $now ??= F7::nowMs();
        try {
            return Tx::run(static fn (): array => self::read($f, $search, $now));
        } catch (Throwable) {
            return []; // Kaynak: serveSponsored(...).catch(()=>[])
        }
    }

    /** @return list<array<string,mixed>> */
    private static function read(SearchFilter $f, SearchService $search, int $now): array
    {
        $date = gmdate('Y-m-d', intdiv($now + 3 * 3600000, 1000));
        $placement = self::placement($f);
        $candidates = [];
        foreach ($f->category !== '' ? [$f->category] : Taxonomy::categoryIds() as $category) {
            $market = AdRules::marketId(['placement' => $placement, 'city' => $f->city, 'category' => $category]);
            $holds = DB::table('ad_inventory_days')->where('market_id', $market)->where('date', $date)->value('holds');
            foreach (AdRules::holds($holds === null ? null : F7::list($holds), $now) as $h) {
                $candidates[] = $h + ['marketId' => $market];
            }
        }
        usort($candidates, static fn (array $a, array $b): int => strcmp($a['reservationId'], $b['reservationId']));
        $out = [];
        $seen = [];
        foreach (self::rotate($candidates, $now) as $slot) {
            $item = self::one($slot, $f, $search, $now);
            if ($item !== null && ! isset($seen[$item['business']['id']])) {
                $seen[$item['business']['id']] = true;
                $out[] = $item;
            }
        }

        return array_slice($out, 0, 3);
    }

    /** @param array{reservationId:string,expiresAtMs:int,marketId:string} $slot @return array<string,mixed>|null */
    private static function one(array $slot, SearchFilter $f, SearchService $search, int $now): ?array
    {
        $r = AdStore::reservation($slot['reservationId'], false);
        $uuid = F7::uuid();
        $doc = CampaignRules::documentId();
        if ($r === null || ($r['status'] ?? null) !== 'paid' || ! F7::ok($uuid, $r['campaignId'] ?? null) || ! F7::ok($uuid, $r['orderId'] ?? null) || ! F7::ok($doc, $r['businessId'] ?? null) || ! F7::ok($doc, $r['userId'] ?? null)) {
            return null;
        }
        $campaignId = $r['campaignId'];
        $orderId = $r['orderId'];
        $bid = $r['businessId'];
        $c = AdStore::campaign($campaignId);
        $o = AdStore::order($orderId, false);
        $b = Docs::business(Rows::business($bid));
        $m = Docs::membership(Rows::member($r['userId'], $bid));
        $anchor = DB::table('ad_campaign_reservations')->where('campaign_id', $campaignId)->first();
        if ($c === null || $o === null || $b === null || $m === null || preg_match('/^[a-f0-9]{64}$/D', (string) ($o['receiptId'] ?? '')) !== 1
            || $anchor === null || $anchor->reservation_id !== $slot['reservationId'] || (int) $anchor->expires_at_ms !== $r['expiresAtMs'] || $slot['expiresAtMs'] !== $r['expiresAtMs']) {
            return null;
        }
        $p = DB::table('ad_payment_receipts')->where('id', $o['receiptId'])->first();
        $record = BusinessReader::records([$bid])[$bid] ?? null;
        $visible = $record === null ? null : BusinessReader::publicBusiness($bid, $record);
        if ($visible === null || $p === null) {
            return null;
        }
        if (! BrandDelivery::publicationAllowed(array_values(array_filter([$r['userId'], $c['ownerUid'] ?? null, $b['ownerUid'] ?? null], static fn ($v): bool => $v !== null)))) {
            return null;
        }
        $item = self::eligible($campaignId, $slot['reservationId'], $orderId, $slot['marketId'], $c, $r, $o, $p, $b, $m, $visible, $f, $search, $now);
        if ($item !== null && AdMetrics::enabled()) {
            try {
                $item['metricToken'] = AdMetrics::token($campaignId, $bid, $item['expiresAtMs'], AdMetrics::secret(), $now);
            } catch (Throwable) {
            }
        }

        return $item;
    }

    /**
     * sponsoredEligible
     *
     * @param  array<string,mixed>  $campaign
     * @param  array<string,mixed>  $r
     * @param  array<string,mixed>  $o
     * @param  array<string,mixed>  $b
     * @param  array<string,mixed>  $m
     * @param  array<string,mixed>  $visible
     * @return array<string,mixed>|null
     */
    public static function eligible(string $campaignId, string $reservationId, string $orderId, string $marketId, array $campaign, array $r, array $o, object $p, array $b, array $m, array $visible, SearchFilter $f, SearchService $search, int $now): ?array
    {
        $c = CampaignRules::view($campaignId, $campaign);
        if ($c === null || $c['status'] !== 'scheduled') {
            return null;
        }
        $start = F7::trStart($c['startDate']);
        if ($start === null) {
            return null;
        }
        $end = $start + $c['days'] * F7::DAY;
        if ($now < $start || $now >= $end || $c['placement'] !== self::placement($f) || $c['city'] !== $f->city || ($f->category !== '' && $c['category'] !== $f->category)
            || $c['businessId'] !== $visible['id'] || ! in_array($c['category'], $visible['categories'], true) || $visible['ownership'] !== 'verified' || $visible['coordinateStatus'] !== 'verified'
            || ($c['approvedProfileVersion'] ?? null) !== ($b['profileVersion'] ?? 0) || ! Policy::ownsCampaignBusiness((string) ($o['userId'] ?? ''), $c['businessId'], $b, $m)) {
            return null;
        }
        if (($o['status'] ?? null) !== 'paid' || $r['status'] !== 'paid' || $p->outcome !== 'paid' || $p->reason !== '' || ($o['reservationId'] ?? null) !== $reservationId || $r['orderId'] !== $orderId
            || $p->order_id !== $orderId || ($o['campaignId'] ?? null) !== $campaignId || $r['campaignId'] !== $campaignId || ($o['businessId'] ?? null) !== $c['businessId'] || $r['businessId'] !== $c['businessId']
            || $r['userId'] !== ($o['userId'] ?? null) || $r['marketId'] !== $marketId || $r['expiresAtMs'] !== $end || ($o['campaignVersion'] ?? null) !== $c['version'] - 1
            || $r['campaignVersion'] !== $o['campaignVersion'] || ($o['totalMinor'] ?? null) !== (int) $p->amount_minor || ($o['currency'] ?? null) !== $p->currency
            || $r['totalMinor'] !== $o['totalMinor'] || $r['currency'] !== $o['currency'] || ($o['provider'] ?? null) !== $p->provider) {
            return null;
        }
        $matched = $search->matches($visible, $f);

        return $matched === null ? null : ['business' => $matched, 'headline' => $c['headline'], 'description' => $c['description'], 'expiresAtMs' => $end];
    }
}
