<?php

namespace App\Domain\Brand;

use App\Domain\Account\AccountFence;
use App\Domain\Ads\AdRules;
use App\Domain\Catalogue\Taxonomy;
use App\Domain\Payments\F7;
use App\Support\Tx;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Support\Facades\DB;
use Throwable;

/** core/brand-delivery.ts + server/brand-delivery.ts: ic degerlendirme; yazim, saglayici cagrisi veya otomatik yayin yok. */
final class BrandDelivery
{
    public const LABELS = ['disabled' => 'Yayın uygunluğu önizlemesi kapalı.', 'invalid' => 'Şehir, kategori veya reklam referansı geçersiz.', 'acceptance' => 'Geçerli kesin kabul kaydı bulunamadı.', 'campaign' => 'Kampanya onayı veya sürümü uygun değil.', 'schedule' => 'Reklam şu an yayın tarihleri dışında.', 'targeting' => 'Reklam seçilen şehir veya kategoriye uygun değil.', 'payment' => 'Ödeme doğrulaması güncel veya tutarlı değil.', 'capacity' => 'Ayrılmış reklam kapasitesi doğrulanamadı.', 'media' => 'Bağlı banner görseli doğrulanamadı.', 'eligible' => 'Seçilen kapsamda yayın koşulları uygun.'];

    /** brandPublicContext (placement, city, category) */
    public static function context(): Z
    {
        return Z::strictObject([
            'placement' => Z::enum(BrandRules::PLACEMENTS),
            'city' => Z::string()->refine(static fn (string $v): bool => in_array($v, Taxonomy::cities(), true)),
            'category' => Z::string()->refine(static fn (string $v): bool => $v === '' || Taxonomy::category($v) !== null),
        ])->refine(static fn (array $v): bool => $v['placement'] !== 'category_banner' || $v['category'] !== '');
    }

    /** brandDeliveryInput */
    public static function input(): Z
    {
        return Z::strictObject([
            'reservationId' => F7::uuid(),
            'placement' => Z::enum(BrandRules::PLACEMENTS),
            'city' => Z::string()->refine(static fn (string $v): bool => in_array($v, Taxonomy::cities(), true)),
            'category' => Z::string()->refine(static fn (string $v): bool => $v === '' || Taxonomy::category($v) !== null),
        ])->refine(static fn (array $v): bool => $v['placement'] !== 'category_banner' || $v['category'] !== '');
    }

    /** brandPreviewImageInput */
    public static function imageInput(): Z
    {
        return Z::strictObject([
            'reservationId' => F7::uuid(),
            'placement' => Z::enum(BrandRules::PLACEMENTS),
            'city' => Z::string()->refine(static fn (string $v): bool => in_array($v, Taxonomy::cities(), true)),
            'category' => Z::string()->refine(static fn (string $v): bool => $v === '' || Taxonomy::category($v) !== null),
            'imageId' => F7::uuid(),
        ])->refine(static fn (array $v): bool => $v['placement'] !== 'category_banner' || $v['category'] !== '');
    }

    private static function deny(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'ad' => null];
    }

    public static function evaluate(mixed $raw, bool $enabled, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        if (! $enabled) {
            return self::deny('disabled');
        }
        try {
            self::input()->parse($raw);
        } catch (ZodError) {
            return self::deny('invalid');
        }

        return Tx::run(static fn (): array => self::evaluateIn($raw, $now));
    }

    /** evaluateBrandDeliveryInTransaction */
    public static function evaluateIn(mixed $raw, int $now): array
    {
        try {
            $q = self::input()->parse($raw);
        } catch (ZodError) {
            return self::deny('invalid');
        }
        if ($now < 0) {
            return self::deny('invalid');
        }
        $id = $q['reservationId'];
        $a = BrandStore::acceptance($id, false);
        $o = BrandStore::order($id, false);
        $r = BrandStore::reservation($id, false);
        $s = BrandStore::paymentState($id, false);
        $binding = BrandStore::binding($id, false);
        try {
            $a = BrandCancellation::acceptanceRecord($a) ?? throw new ZodError('invalid_type');
            $o = BrandRules::orderRecord($o ?? []);
        } catch (Throwable) {
            return self::deny('acceptance');
        }
        if ($a['reservationId'] !== $id || $o['reservationId'] !== $id || $a['campaignId'] !== $o['campaignId'] || $a['campaignVersion'] !== $o['campaignVersion'] || $a['totalMinor'] !== $o['totalMinor'] || $a['currency'] !== $o['currency'] || $a['acceptedAtMs'] < $o['createdAtMs'] || $a['acceptedAtMs'] >= $o['expiresAtMs'] || $a['acceptedAtMs'] > $now) {
            return self::deny('acceptance');
        }
        $campaign = BrandStore::campaign($a['campaignId'], false);
        $c = $campaign === null ? null : AdRules::parseOk(BrandRules::creative(), (object) $campaign['creative']);
        if ($campaign === null || $c === null || $campaign['status'] !== 'approved' || $campaign['version'] !== $a['campaignVersion']) {
            return self::deny('campaign');
        }
        $start = F7::trStart($c['startDate']);
        $end = F7::trStart($c['endDate']) + F7::DAY;
        $dates = BrandRules::dates($c['startDate'], intdiv($end - $start, F7::DAY));
        if ($a['committedUntilMs'] !== $end || $a['dates'] !== $dates || $a['placement'] !== $c['placement']) {
            return self::deny('acceptance');
        }
        if ($now < $start || $now >= $end) {
            return self::deny('schedule');
        }
        if ($q['placement'] !== $c['placement'] || ($c['city'] !== '' && $c['city'] !== $q['city']) || ($c['category'] !== '' && $c['category'] !== $q['category'])) {
            return self::deny('targeting');
        }
        if (($s['status'] ?? null) !== 'verified' || ($s['primaryReceiptId'] ?? null) !== $a['receiptId'] || $binding === null) {
            return self::deny('payment');
        }
        $receipt = BrandStore::receipt($a['receiptId'], false);
        [, $anchor] = BrandStore::anchor($a['campaignId'], false);
        $dayRow = DB::table('brand_inventory_days')->where('placement', $c['placement'])->where('date', F7::trDate($now))->first();
        if ($receipt === null || $receipt['recordId'] !== $id || $receipt['outcome'] !== 'verified' || $receipt['reason'] !== '' || $receipt['currency'] !== $o['currency'] || $receipt['amountMinor'] !== $o['totalMinor'] || $receipt['provider'] !== $binding['provider'] || $receipt['merchantId'] !== $binding['merchantId'] || F7::sha([$receipt['provider'], $receipt['merchantId'], $receipt['paymentId']]) !== $a['receiptId'] || ! is_int($receipt['capturedAtMs']) || $receipt['capturedAtMs'] < $o['createdAtMs'] || $receipt['capturedAtMs'] >= $o['expiresAtMs'] || $receipt['capturedAtMs'] > $a['acceptedAtMs']) {
            return self::deny('payment');
        }
        $m = $r['market'] ?? null;
        if ($r === null || $r['status'] !== 'committed' || $r['campaignId'] !== $a['campaignId'] || $r['campaignVersion'] !== $o['campaignVersion'] || $r['capacityVersion'] !== $o['capacityVersion'] || $r['tariffVersion'] !== $o['tariffVersion'] || $r['totalMinor'] !== $o['totalMinor'] || $r['currency'] !== $o['currency'] || $r['placement'] !== $c['placement'] || ($m['city'] ?? null) !== $c['city'] || ($m['category'] ?? null) !== $c['category'] || ($m['placement'] ?? null) !== $c['placement'] || ($r['committedUntilMs'] ?? null) !== $end || $r['dates'] !== $dates || ($anchor['reservationId'] ?? null) !== $id || ($anchor['expiresAtMs'] ?? null) !== $end) {
            return self::deny('capacity');
        }
        try {
            $holds = AdRules::holds($dayRow === null ? null : F7::list($dayRow->holds), $now);
            if (count(array_unique(array_column($holds, 'reservationId'))) !== count($holds) || count(array_filter($holds, static fn (array $h): bool => $h['reservationId'] === $id && $h['expiresAtMs'] === $end)) !== 1) {
                return self::deny('capacity');
            }
        } catch (Throwable) {
            return self::deny('capacity');
        }
        $asset = isset($campaign['asset']) ? AdRules::parseOk(BrandRules::asset(), (object) $campaign['asset']) : null;
        if ($asset === null) {
            return self::deny('media');
        }
        $upload = F7::row('brand_media_uploads', 'id', $asset['id']);
        if ($upload?->status !== 'attached' || $upload->campaign_id !== $a['campaignId'] || $upload->sha256 !== $asset['sha256']) {
            return self::deny('media');
        }
        if (! self::publicationAllowed(array_merge([$r['actorId'], $campaign['lastEditorId']], $upload->actor_id !== null ? [$upload->actor_id] : []))) {
            return self::deny('campaign');
        }

        return ['eligible' => true, 'reason' => 'eligible', 'ad' => ['campaignId' => $a['campaignId'], 'brand' => $c['brand'], 'headline' => $c['headline'], 'description' => $c['description'], 'url' => $c['url'], 'placement' => $c['placement'], 'image' => ['id' => $asset['id'], 'width' => $asset['width'], 'height' => $asset['height']], 'sponsored' => true]];
    }

    /** publicationAllowed: bilinmeyen kimlik veya silme cercevesi ucretli yerlestirmeyi uygun yapmaz. */
    public static function publicationAllowed(array $subjects): bool
    {
        try {
            if ($subjects === [] || count($subjects) > 5) {
                return false;
            }
            $uids = array_unique(array_map(static fn (mixed $v): string => Z::string()->regex('/^[A-Za-z0-9_-]{1,128}$/')->parse($v), $subjects));
            sort($uids);
            foreach ($uids as $uid) {
                if (AccountFence::paused($uid)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
