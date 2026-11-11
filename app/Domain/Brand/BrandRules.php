<?php

namespace App\Domain\Brand;

use App\Domain\Ads\CampaignRules;
use App\Domain\Payments\F7;
use App\Support\Z;
use App\Support\ZodError;
use RuntimeException;

/** core/brand-pricing.ts, brand-campaigns.ts, brand-inventory.ts, brand-orders.ts, brand-media.ts saf kurallari. */
final class BrandRules
{
    public const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'paused'];

    public const PLACEMENTS = ['home_banner', 'category_banner'];

    private static function city(): Z
    {
        return Z::string()->refine(static fn (string $v): bool => $v === '' || CampaignRules::isCity($v));
    }

    private static function category(): Z
    {
        return Z::string()->refine(static fn (string $v): bool => $v === '' || CampaignRules::isCategory($v));
    }

    /** brandMarket */
    public static function market(): Z
    {
        return Z::strictObject(['placement' => Z::enum(self::PLACEMENTS), 'city' => self::city(), 'category' => self::category()])
            ->refine(static fn (array $v): bool => $v['placement'] !== 'category_banner' || $v['category'] !== '');
    }

    public static function tariff(): Z
    {
        return Z::strictObject(['market' => self::market(), 'enabled' => Z::boolean(), 'dailyMinor' => Z::number()->int()->min(1)->max(100_000_000), 'taxBasisPoints' => Z::number()->int()->min(0)->max(10000)]);
    }

    public static function tariffUpdate(): Z
    {
        return Z::strictObject(['version' => Z::number()->int()->min(0)->max(1_000_000_000), 'value' => self::tariff(), 'reason' => Z::string()->trim()->min(10)->max(1000)]);
    }

    public static function quoteInput(): Z
    {
        return Z::strictObject(['campaignId' => F7::uuid(), 'campaignVersion' => Z::number()->int()->positive(), 'tariffVersion' => Z::number()->int()->positive()]);
    }

    /** brandMarketKey */
    public static function marketKey(array $m): string
    {
        $v = self::market()->parse((object) $m);

        return F7::json([$v['placement'], $v['city'], $v['category']]);
    }

    public static function tariffId(array $m): string
    {
        return hash('sha256', self::marketKey($m));
    }

    /** brandTariffView */
    public static function tariffView(array $raw): array
    {
        $market = $raw['market'] ?? null;

        return self::tariff()->parse((object) ['market' => is_array($market) ? (object) $market : $market, 'enabled' => $raw['enabled'] ?? null, 'dailyMinor' => $raw['dailyMinor'] ?? null, 'taxBasisPoints' => $raw['taxBasisPoints'] ?? null])
            + ['version' => Z::number()->int()->positive()->parse($raw['version'] ?? null)];
    }

    private static function httpsUrl(string $s): bool
    {
        $u = parse_url($s);
        if (! is_array($u) || strtolower($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['port']) || ! isset($u['host'])) {
            return false;
        }
        $host = strtolower($u['host']);

        return preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/iD', $host) === 1 && ! str_ends_with($host, '.localhost') && ! str_ends_with($host, '.local') && preg_match('/^https:\/\/[^\s\/?#]+/iD', $s) === 1;
    }

    /** brandCreative */
    public static function creative(): Z
    {
        $date = F7::date();

        return Z::strictObject([
            'brand' => Z::string()->trim()->min(2)->max(100),
            'headline' => Z::string()->trim()->min(5)->max(100),
            'description' => Z::string()->trim()->min(10)->max(400),
            'url' => Z::string()->trim()->max(500)->refine(static fn (string $s): bool => self::httpsUrl($s)),
            'placement' => Z::enum(self::PLACEMENTS),
            'city' => self::city(),
            'category' => self::category(),
            'startDate' => $date,
            'endDate' => $date,
        ])->refine(static fn (array $v): bool => $v['placement'] !== 'category_banner' || $v['category'] !== '')
            ->refine(static function (array $v): bool {
                $d = F7::utcStart($v['endDate']) - F7::utcStart($v['startDate']);

                return $d >= 0 && $d < 90 * F7::DAY;
            });
    }

    /** brandAction */
    public static function action(): Z
    {
        $base = ['id' => F7::uuid(), 'version' => Z::number()->int()->nonnegative()];
        $simple = Z::strictObject($base + ['action' => Z::enum(['submit', 'pause', 'remove_image'])]);
        $review = Z::strictObject($base + ['action' => Z::enum(['approve', 'reject']), 'note' => Z::string()->trim()->min(10)->max(1000), 'rightsChecked' => Z::boolean()]);

        return Z::discriminatedUnion('action', [
            'save' => Z::strictObject($base + ['action' => Z::literal('save'), 'value' => self::creative()]),
            'submit' => $simple, 'pause' => $simple, 'remove_image' => $simple, 'approve' => $review, 'reject' => $review,
        ]);
    }

    /** brandAssetSchema */
    public static function asset(): Z
    {
        return Z::strictObject(['id' => F7::uuid(), 'sha256' => F7::hash(), 'width' => Z::number()->int()->positive()->max(1920), 'height' => Z::number()->int()->positive()->max(1920), 'bytes' => Z::number()->int()->positive()->max(2500000)]);
    }

    /**
     * brandTransition
     *
     * @param  array<string,mixed>|null  $previous
     * @return array<string,mixed>
     */
    public static function transition(?array $previous, array $a, string $uid, int $now): array
    {
        if (($previous['version'] ?? 0) !== $a['version']) {
            throw new RuntimeException('conflict');
        }
        if ($a['action'] === 'save') {
            if ($previous !== null && ! in_array($previous['status'], ['draft', 'rejected', 'paused'], true)) {
                throw new RuntimeException('state');
            }

            return (isset($previous['asset']) ? ['asset' => $previous['asset']] : []) + ['mediaAttempts' => $previous['mediaAttempts'] ?? 0, 'creative' => $a['value'], 'status' => 'draft', 'version' => $a['version'] + 1, 'note' => '', 'lastEditorId' => $uid, 'createdAtMs' => $previous['createdAtMs'] ?? $now, 'updatedAtMs' => $now];
        }
        if ($previous === null) {
            throw new RuntimeException('missing');
        }
        if ($a['action'] === 'remove_image') {
            if (! isset($previous['asset']) || ! in_array($previous['status'], ['draft', 'rejected', 'paused'], true)) {
                throw new RuntimeException('state');
            }
            $rest = $previous;
            unset($rest['asset']);

            return array_merge($rest, ['status' => 'draft', 'note' => '', 'lastEditorId' => $uid, 'version' => $a['version'] + 1, 'updatedAtMs' => $now]);
        }
        $start = F7::trStart($previous['creative']['startDate']);
        if ($a['action'] === 'submit') {
            if ($previous['status'] !== 'draft') {
                throw new RuntimeException('state');
            }
            if ($start <= $now || $start > $now + 180 * F7::DAY) {
                throw new RuntimeException('date');
            }
            $status = 'pending';
        } elseif ($a['action'] === 'pause') {
            if (! in_array($previous['status'], ['pending', 'approved'], true)) {
                throw new RuntimeException('state');
            }
            $status = 'paused';
        } else {
            if ($previous['status'] !== 'pending' || $previous['lastEditorId'] === $uid) {
                throw new RuntimeException('review');
            }
            if ($a['action'] === 'approve' && (! $a['rightsChecked'] || $start <= $now)) {
                throw new RuntimeException('review');
            }
            $status = $a['action'] === 'approve' ? 'approved' : 'rejected';
        }

        return array_merge($previous, ['status' => $status, 'version' => $a['version'] + 1, 'updatedAtMs' => $now], array_key_exists('note', $a) ? ['note' => $a['note']] : []);
    }

    /** brandView */
    public static function view(string $id, array $d, string $uid): array
    {
        $asset = isset($d['asset']) ? self::asset()->parse((object) $d['asset']) : null;

        return ['id' => $id] + ($asset !== null ? ['image' => ['id' => $asset['id'], 'width' => $asset['width'], 'height' => $asset['height'], 'bytes' => $asset['bytes']]] : [])
            + ['creative' => self::creative()->parse((object) $d['creative']), 'status' => $d['status'], 'version' => $d['version'], 'note' => $d['note'], 'canReview' => $d['status'] === 'pending' && $d['lastEditorId'] !== $uid];
    }

    /**
     * brandPrice: tahmini teklif; rezervasyon ve odeme degildir.
     *
     * @param  array{campaignId:string,campaignVersion:int,tariffVersion:int}  $input
     */
    public static function price(array $campaign, array $tariff, array $input, int $now): array
    {
        self::quoteInput()->parse((object) $input);
        $c = self::creative()->parse((object) $campaign['creative']);
        $t = self::tariffView($tariff);
        $version = $campaign['version'] ?? null;
        if ($now < 0 || ! F7::safeInt($version) || $version < 1 || $version !== $input['campaignVersion'] || $t['version'] !== $input['tariffVersion']) {
            throw new RuntimeException('version');
        }
        if (! in_array($campaign['status'] ?? null, self::STATUSES, true) || ! $t['enabled'] || self::marketKey(['placement' => $c['placement'], 'city' => $c['city'], 'category' => $c['category']]) !== self::marketKey($t['market'])) {
            throw new RuntimeException('market');
        }
        $start = F7::trStart($c['startDate']);
        if ($start <= $now || $start > $now + 180 * F7::DAY) {
            throw new RuntimeException('date');
        }
        $days = intdiv(F7::utcStart($c['endDate']) - F7::utcStart($c['startDate']), F7::DAY) + 1;
        $subtotal = $t['dailyMinor'] * $days;
        $tax = F7::round($subtotal * $t['taxBasisPoints'] / 10000);
        $total = $subtotal + $tax;
        if (! F7::safeInt($subtotal) || ! F7::safeInt($tax) || ! F7::safeInt($total)) {
            throw new RuntimeException('amount');
        }

        return ['kind' => 'estimate', 'campaignId' => $input['campaignId'], 'campaignVersion' => $version, 'tariffVersion' => $t['version'], 'market' => $t['market'], 'startDate' => $c['startDate'], 'endDate' => $c['endDate'], 'days' => $days, 'currency' => 'TRY', 'dailyMinor' => $t['dailyMinor'], 'taxBasisPoints' => $t['taxBasisPoints'], 'subtotalMinor' => $subtotal, 'taxMinor' => $tax, 'totalMinor' => $total, 'createdAtMs' => $now, 'expiresAtMs' => min($now + 15 * 60000, $start), 'reservation' => false, 'payable' => false];
    }

    public static function capacity(): Z
    {
        return Z::strictObject(['enabled' => Z::boolean(), 'capacity' => Z::number()->int()->min(1)->max(20), 'version' => Z::number()->int()->positive()]);
    }

    public static function capacityUpdate(): Z
    {
        return Z::strictObject(['placement' => Z::enum(self::PLACEMENTS), 'version' => Z::number()->int()->nonnegative(), 'enabled' => Z::boolean(), 'capacity' => Z::number()->int()->min(1)->max(20), 'reason' => Z::string()->trim()->min(10)->max(1000)]);
    }

    public static function reservationInput(): Z
    {
        return Z::strictObject(['campaignId' => F7::uuid(), 'campaignVersion' => Z::number()->int()->positive(), 'tariffVersion' => Z::number()->int()->positive(), 'requestId' => F7::uuid(), 'capacityVersion' => Z::number()->int()->positive()]);
    }

    public static function campaignLookup(): Z
    {
        return Z::strictObject(['campaignId' => F7::uuid()]);
    }

    /** @return list<string> brandDates */
    public static function dates(string $start, int $days): array
    {
        $s = F7::utcStart($start) ?? throw new RuntimeException('date');
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[] = F7::isoDate($s + $i * F7::DAY);
        }

        return $out;
    }

    public static function orderInput(): Z
    {
        return Z::strictObject(['reservationId' => F7::uuid()]);
    }

    /** brandOrderRecord */
    public static function orderRecord(array $o): array
    {
        $minor = Z::number()->int()->nonnegative()->max(18_000_000_000);
        $v = Z::strictObject([
            'reservationId' => F7::uuid(), 'campaignId' => F7::uuid(), 'campaignVersion' => Z::number()->int()->positive(), 'tariffVersion' => Z::number()->int()->positive(),
            'capacityVersion' => Z::number()->int()->positive(), 'currency' => Z::literal('TRY'), 'subtotalMinor' => $minor, 'taxMinor' => $minor, 'totalMinor' => $minor,
            'createdAtMs' => Z::number()->int()->nonnegative(), 'expiresAtMs' => Z::number()->int()->positive(), 'status' => Z::literal('prepared'),
        ])->parse((object) $o);
        if (! ($v['totalMinor'] > 0 && $v['totalMinor'] === $v['subtotalMinor'] + $v['taxMinor'] && $v['expiresAtMs'] > $v['createdAtMs'])) {
            throw new ZodError('custom', 'Invalid input');
        }

        return $v;
    }

    public static function orderView(array $o, string $status): array
    {
        return array_merge($o, ['id' => $o['reservationId'], 'status' => $status, 'payable' => false]);
    }

    public static function mayChangeImage(array $row, int $version): bool
    {
        return ($row['version'] ?? null) === $version && in_array($row['status'] ?? null, ['draft', 'rejected', 'paused'], true);
    }

    /** brandImageViewed */
    public static function imageViewed(array $asset, int $version, ?array $view, int $now): bool
    {
        return $view !== null && ($view['id'] ?? null) === $asset['id'] && ($view['sha256'] ?? null) === $asset['sha256'] && ($view['version'] ?? null) === $version && is_int($view['viewedAtMs'] ?? null) && $view['viewedAtMs'] <= $now && $view['viewedAtMs'] > $now - 3600000;
    }
}
