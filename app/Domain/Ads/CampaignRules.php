<?php

namespace App\Domain\Ads;

use App\Domain\Catalogue\Taxonomy;
use App\Domain\Payments\F7;
use App\Support\Query;
use App\Support\Z;
use App\Support\ZodError;
use RuntimeException;

/** packages/core/campaigns.ts ve owner-campaigns-client.ts saf kurallari. */
final class CampaignRules
{
    public const STATUSES = ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'scheduled'];

    public const FIELDS = ['placement', 'city', 'category', 'days', 'startDate', 'headline', 'description'];

    public static function documentId(): Z
    {
        return Z::string()->regex('/^[a-zA-Z0-9_-]{1,120}$/');
    }

    public static function isCity(mixed $v): bool
    {
        return is_string($v) && in_array($v, Taxonomy::cities(), true);
    }

    public static function isCategory(mixed $v): bool
    {
        return is_string($v) && Taxonomy::category($v) !== null;
    }

    /** campaignInput */
    public static function input(): Z
    {
        return Z::strictObject([
            'placement' => Z::enum(['search', 'category', 'home']),
            'city' => Z::string()->refine(static fn (string $v): bool => self::isCity($v)),
            'category' => Z::string()->refine(static fn (string $v): bool => self::isCategory($v)),
            'days' => Z::literal(7, 30),
            'startDate' => Z::string()->regex('/^\d{4}-\d{2}-\d{2}$/')->refine(static fn (string $v): bool => F7::utcStart($v) !== null),
            'headline' => Z::string()->trim()->min(5)->max(80),
            'description' => Z::string()->trim()->min(10)->max(300),
        ]);
    }

    /** campaignAction (discriminatedUnion 'action') */
    public static function action(): Z
    {
        $base = ['id' => F7::uuid(), 'businessId' => self::documentId(), 'version' => Z::number()->int()->nonnegative()];
        $save = Z::strictObject($base + ['action' => Z::literal('save'), 'value' => self::input()]);
        $simple = Z::strictObject($base + ['action' => Z::enum(['submit', 'cancel'])]);
        $review = Z::strictObject($base + ['action' => Z::enum(['approve', 'reject']), 'reason' => Z::string()->trim()->min(10)->max(1000)]);

        return Z::discriminatedUnion('action', ['save' => $save, 'submit' => $simple, 'cancel' => $simple, 'approve' => $review, 'reject' => $review]);
    }

    /** @param array<string,mixed> $b @param array<string,mixed>|null $m */
    public static function owns(string $uid, string $bid, array $b, ?array $m): bool
    {
        return ($b['sample'] ?? null) !== true && ($b['ownership'] ?? null) === 'verified' && $m !== null && ($m['active'] ?? null) === true && ($m['userId'] ?? null) === $uid && ($m['businessId'] ?? null) === $bid && ($m['role'] ?? null) === 'owner';
    }

    public static function scheduleEligible(string $startDate, int $now): bool
    {
        $start = F7::trStart($startDate);

        return $start !== null && $start > $now && $start <= $now + 180 * F7::DAY;
    }

    /** @param array<string,mixed> $b */
    private static function coordinate(array $b): bool
    {
        $lat = $b['latitude'] ?? null;
        $lng = $b['longitude'] ?? null;

        return ($b['coordinateStatus'] ?? null) === 'verified' && (is_int($lat) || is_float($lat)) && is_finite((float) $lat) && abs($lat) <= 90 && (is_int($lng) || is_float($lng)) && is_finite((float) $lng) && abs($lng) <= 180;
    }

    /** @param array<string,mixed> $b @param array<string,mixed> $v */
    public static function eligible(array $b, array $v, int $now): bool
    {
        return ($b['ownership'] ?? null) === 'verified' && ($b['published'] ?? null) === true && ($b['sample'] ?? null) !== true && self::coordinate($b)
            && ($b['city'] ?? null) === $v['city'] && is_array($b['categories'] ?? null) && in_array($v['category'], $b['categories'], true) && self::scheduleEligible($v['startDate'], $now);
    }

    /**
     * campaignTransition
     *
     * @param  array<string,mixed>|null  $previous
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @param  array{owner:bool,admin:bool,affiliated:bool}  $auth
     * @return array<string,mixed>
     */
    public static function transition(?array $previous, array $a, array $b, array $auth, int $now): array
    {
        $action = $a['action'];
        $review = $action === 'approve' || $action === 'reject';
        if ($review ? (! $auth['admin'] || $auth['affiliated']) : ! $auth['owner']) {
            throw new RuntimeException('forbidden');
        }
        if (($previous['version'] ?? 0) !== $a['version'] || ($previous !== null && $previous['businessId'] !== $a['businessId'])) {
            throw new RuntimeException('conflict');
        }
        $name = is_string($b['name'] ?? null) ? mb_substr($b['name'], 0, 160) : null;
        if ($action === 'save') {
            if ($previous !== null && ! in_array($previous['status'], ['draft', 'rejected'], true)) {
                throw new RuntimeException('state');
            }
            if (($b['city'] ?? null) !== $a['value']['city'] || ! is_array($b['categories'] ?? null) || ! in_array($a['value']['category'], $b['categories'], true)) {
                throw new RuntimeException('target');
            }

            return self::input()->parse((object) $a['value']) + ['businessId' => $a['businessId'], 'businessName' => $name ?? '', 'status' => 'draft', 'version' => $a['version'] + 1, 'reason' => '', 'createdAtMs' => $previous['createdAtMs'] ?? $now, 'updatedAtMs' => $now];
        }
        if ($previous === null) {
            throw new RuntimeException('missing');
        }
        if ($action === 'submit') {
            if ($previous['status'] !== 'draft' || ! self::eligible($b, $previous, $now)) {
                throw new RuntimeException('eligibility');
            }
            $status = 'pending';
        } elseif ($action === 'cancel') {
            if (! in_array($previous['status'], ['draft', 'pending', 'approved', 'rejected'], true)) {
                throw new RuntimeException('state');
            }
            $status = 'cancelled';
        } else {
            if ($previous['status'] !== 'pending') {
                throw new RuntimeException('state');
            }
            if ($action === 'approve' && ! self::eligible($b, $previous, $now)) {
                throw new RuntimeException('eligibility');
            }
            $status = $action === 'approve' ? 'approved' : 'rejected';
        }
        $next = $previous;
        unset($next['id']);
        if ($action === 'approve') {
            $next['approvedProfileVersion'] = is_int($b['profileVersion'] ?? null) ? $b['profileVersion'] : 0;
        }

        return array_merge($next, ['businessName' => $name ?? ($previous['businessName'] ?? ''), 'status' => $status, 'version' => $a['version'] + 1, 'reason' => $review ? $a['reason'] : $previous['reason'], 'updatedAtMs' => $now]);
    }

    /**
     * campaignView
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null
     */
    public static function view(string $id, array $row): ?array
    {
        try {
            $data = self::input()->parse((object) array_intersect_key($row, array_flip(self::FIELDS)));
        } catch (ZodError) {
            return null;
        }
        $version = $row['version'] ?? null;
        if (! in_array($row['status'] ?? null, self::STATUSES, true) || ! F7::ok(self::documentId(), $row['businessId'] ?? null) || ! F7::safeInt($version) || $version < 1) {
            return null;
        }
        $approved = $row['approvedProfileVersion'] ?? null;

        return ['id' => $id] + $data + (F7::safeInt($approved) ? ['approvedProfileVersion' => $approved] : []) + [
            'businessId' => (string) $row['businessId'],
            'businessName' => is_string($row['businessName'] ?? null) ? mb_substr($row['businessName'], 0, 160) : '',
            'status' => $row['status'],
            'version' => $version,
            'reason' => is_string($row['reason'] ?? null) ? mb_substr($row['reason'], 0, 1000) : '',
            'createdAtMs' => is_int($row['createdAtMs'] ?? null) ? $row['createdAtMs'] : 0,
            'updatedAtMs' => is_int($row['updatedAtMs'] ?? null) ? $row['updatedAtMs'] : 0,
        ];
    }

    /**
     * projectCampaignBranch (campaignBranch.parse)
     *
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>
     */
    public static function branch(string $id, array $b): array
    {
        $cats = is_array($b['categories'] ?? null) ? array_values(array_filter($b['categories'], static fn (mixed $v): bool => self::isCategory($v))) : [];
        $value = ['id' => $id, 'name' => $b['name'] ?? null, 'city' => is_string($b['city'] ?? null) ? $b['city'] : '', 'categories' => $cats, 'published' => ($b['published'] ?? null) === true, 'coordinateVerified' => self::coordinate($b)];
        self::documentId()->parse($id);
        Z::string()->min(1)->max(200)->parse($value['name']);
        Z::string()->max(100)->parse($value['city']);
        if (count(array_unique($cats)) !== count($cats)) {
            throw new ZodError('custom', 'Invalid input');
        }

        return $value;
    }

    /** campaignHttpInput: hata kodu RuntimeException/ZodError mesajinda. */
    public static function http(string $method, Query $q, ?string $type): void
    {
        if ($method !== 'GET') {
            if ($q->keys() !== []) {
                throw new RuntimeException('campaign_query');
            }
            if (preg_match('/^application\/json(?:\s*;|$)/iD', $type ?? '') !== 1) {
                throw new RuntimeException('campaign_content_type');
            }

            return;
        }
        $queue = $q->get('queue') === 'true';
        $owner = $q->get('scope') === 'owner';
        $keys = $queue ? ['queue', 'status', 'after'] : ($owner ? ['businessId', 'scope', 'after', 'id'] : ['businessId', 'after']);
        foreach ($q->keys() as $k) {
            if (! in_array($k, $keys, true) || $q->count($k) !== 1) {
                throw new RuntimeException('campaign_query');
            }
        }
        if ($queue) {
            if ($q->has('status')) {
                Z::enum(self::STATUSES)->parse($q->get('status'));
            }
        } else {
            self::documentId()->parse($q->get('businessId'));
        }
        if ($q->has('scope') && ! $owner) {
            throw new RuntimeException('campaign_scope');
        }
        if (($q->get('after') ?? '') !== '') {
            F7::uuid()->parse($q->get('after'));
        }
        if ($q->has('id')) {
            F7::uuid()->parse($q->get('id'));
            if ($q->has('after')) {
                throw new RuntimeException('campaign_lookup');
            }
        }
    }

    /** @return list<string> campaignDates */
    public static function dates(string $startDate, int $days): array
    {
        $start = F7::trStart($startDate) ?? throw new RuntimeException('schedule');
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[] = F7::isoDate($start + 3 * 3600000 + $i * F7::DAY);
        }

        return $out;
    }
}
