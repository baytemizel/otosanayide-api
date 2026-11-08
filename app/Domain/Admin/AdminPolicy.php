<?php

namespace App\Domain\Admin;

use App\Domain\Catalogue\Contact;
use App\Domain\Content\Schemas;
use App\Support\Js;
use Normalizer;
use RuntimeException;

/** core/claim-review, claim-target, business-management, catalogue-* saf kurallari. */
final class AdminPolicy
{
    /** JS dogruluk denetimi: '0' PHP'de bos sayilirdi, kimlik olarak gecerlidir. */
    private static function truthy(mixed $v): bool
    {
        return $v !== null && $v !== '' && $v !== false && $v !== 0;
    }

    // -- claim-review.ts ------------------------------------------------------

    public static function matchesVerifiedEvidence(?array $proof, array $target): bool
    {
        return $proof !== null && self::truthy($target['businessId'] ?? null) && ($proof['status'] ?? null) === 'verified' && ($proof['claimId'] ?? null) === $target['claimId'] && ($proof['businessId'] ?? null) === $target['businessId'] && ($proof['userId'] ?? null) === $target['userId'];
    }

    /** Karar saf; yetkili degerler transaction okumalarindan gelir. */
    public static function decideClaim(array $input, array $claim, string $reviewerId, ?array $business): string
    {
        if ($claim['userId'] === $reviewerId) {
            throw new ClaimReviewConflict('Kendi başvurunu değerlendiremezsin.');
        }
        if (! in_array($claim['status'], ['submitted', 'needs_information'], true)) {
            throw new ClaimReviewConflict('Bu başvuru daha önce sonuçlandırılmış.');
        }
        if ($input['decision'] === 'approve') {
            if (! self::truthy($claim['businessId'] ?? null) || $business === null) {
                throw new ClaimReviewConflict('Önce başvuruyu mevcut bir işletme kaydına bağla.');
            }
            if (! empty($business['sample'])) {
                throw new ClaimReviewConflict('Örnek işletmeye sahiplik verilemez.');
            }
            if (! in_array($business['ownership'] ?? '', ['unclaimed', 'pending'], true) || self::truthy($business['ownerUid'] ?? null)) {
                throw new ClaimReviewConflict('İşletmenin sahiplik durumu yeniden incelenmeli.');
            }

            return 'approved';
        }

        return $input['decision'] === 'reject' ? 'rejected' : 'needs_information';
    }

    // -- claim-target.ts -------------------------------------------------------

    public static function mayMapClaim(?array $claim, string $reviewerId): bool
    {
        return $claim !== null && is_string($claim['userId'] ?? null) && $claim['userId'] !== '' && $claim['userId'] !== $reviewerId && ! self::truthy($claim['businessId'] ?? null) && in_array((string) ($claim['status'] ?? ''), ['submitted', 'needs_information'], true) && ($claim['pendingEvidenceUploads'] ?? 0) === 0;
    }

    public static function eligibleClaimTarget(?array $business): bool
    {
        return $business !== null && ($business['sample'] ?? null) !== true && ! self::truthy($business['ownerUid'] ?? null) && in_array((string) ($business['ownership'] ?? ''), ['unclaimed', 'pending'], true);
    }

    /** businessIdentityText: NFKC + tr kucuk harf + bosluk sadelestirme. */
    public static function businessIdentityText(array $profile): string
    {
        $parts = [];
        foreach ([$profile['name'], $profile['city'], $profile['district'], $profile['address']] as $s) {
            $nfkc = (string) Normalizer::normalize($s, Normalizer::FORM_KC);
            $lower = mb_strtolower(strtr($nfkc, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
            $parts[] = Js::trim((string) preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $lower));
        }

        return implode("\n", $parts);
    }

    // -- business-management.ts ------------------------------------------------

    /**
     * profileProjection. `phone` e164 yoksa (444...) null; `moved` adres/il/ilce/koordinat degisimi.
     * location null iken kaynak `undefined` ile karsilastirir: onceki deger ne olursa olsun tasinmis sayilir.
     */
    public static function profileProjection(array $profile, array $previous): array
    {
        $location = $profile['location'];
        $sameCoord = static fn (string $key): bool => $location !== null && isset($previous[$key]) && (float) $previous[$key] === (float) $location[$key];
        $moved = ($previous['address'] ?? null) !== $profile['address'] || ($previous['city'] ?? null) !== $profile['city'] || ($previous['district'] ?? null) !== $profile['district'] || ! $sameCoord('latitude') || ! $sameCoord('longitude');
        $out = array_diff_key($profile, ['location' => true, 'contactEmail' => true, 'phone' => true]);
        if (array_key_exists('contactEmail', $profile)) {
            $out['contactEmail'] = Contact::businessEmail($profile['contactEmail']) ?? '';
        }
        $out['phone'] = Contact::phone($profile['phone'])['e164'] ?? null;
        $out['latitude'] = $location['latitude'] ?? null;
        $out['longitude'] = $location['longitude'] ?? null;
        $out['coordinateStatus'] = $moved ? 'unverified' : (($previous['coordinateStatus'] ?? null) === 'verified' ? 'verified' : 'unverified');

        return $out;
    }

    // -- catalogue-publication.ts -----------------------------------------------

    /** @param array{uid:string,admin:bool,affiliated:bool} $actor */
    public static function publicationTransition(array $b, array $a, array $actor): array
    {
        if (! $actor['admin'] || $actor['affiliated'] || ($b['ownerUid'] ?? null) === $actor['uid']) {
            throw new RuntimeException('forbidden');
        }
        if (($b['publicationVersion'] ?? 0) !== $a['version'] || ($b['profileVersion'] ?? 0) !== $a['profileVersion']) {
            throw new RuntimeException('stale');
        }
        if (($b['sample'] ?? null) === true) {
            throw new RuntimeException('sample');
        }
        $closed = ['ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false], 'activationVersion' => (int) ($b['activationVersion'] ?? 0) + 1];
        if ($a['action'] === 'withdraw') {
            if (($b['published'] ?? null) !== true) {
                throw new RuntimeException('stale');
            }

            return ['published' => false, 'publicationVersion' => $a['version'] + 1] + $closed;
        }
        if (($b['published'] ?? null) === true) {
            throw new RuntimeException('stale');
        }
        $profile = Schemas::businessProfile()->parse((object) ([
            'name' => $b['name'] ?? null, 'city' => $b['city'] ?? null, 'district' => $b['district'] ?? null, 'address' => $b['address'] ?? null,
            'phone' => $b['phone'] ?? '', 'website' => $b['website'] ?? '',
        ] + (array_key_exists('contactEmail', $b) ? ['contactEmail' => $b['contactEmail']] : []) + [
            'hours' => $b['hours'] ?? '', 'description' => $b['description'] ?? '',
            'categories' => $b['categories'] ?? null, 'services' => $b['services'] ?? null, 'brands' => $b['brands'] ?? null,
            'location' => (object) ['latitude' => $b['latitude'] ?? null, 'longitude' => $b['longitude'] ?? null],
        ]));
        $l = $profile['location'];
        if ($l === null || $l['latitude'] < 35.7 || $l['latitude'] > 42.3 || $l['longitude'] < 25.5 || $l['longitude'] > 45) {
            throw new RuntimeException('location');
        }

        return ['published' => true, 'coordinateStatus' => 'verified', 'publicationVersion' => $a['version'] + 1] + $closed;
    }

    // -- catalogue-draft.ts ---------------------------------------------------

    /** @param array{uid:string,admin:bool,affiliated:bool} $actor */
    public static function mayPrepareCatalogue(array $b, array $actor): bool
    {
        return $actor['admin'] && ! $actor['affiliated'] && ($b['ownerUid'] ?? null) !== $actor['uid'] && ! self::truthy($b['ownerUid'] ?? null) && ($b['ownership'] ?? null) === 'unclaimed' && ($b['published'] ?? null) === false && ($b['sample'] ?? null) !== true;
    }

    /** @param array{uid:string,admin:bool,affiliated:bool} $actor */
    public static function catalogueDraftTransition(array $b, array $input, array $actor): array
    {
        if (! self::mayPrepareCatalogue($b, $actor)) {
            throw new RuntimeException('forbidden');
        }
        if (($b['profileVersion'] ?? 0) !== $input['profileVersion'] || ($b['publicationVersion'] ?? 0) !== $input['publicationVersion']) {
            throw new RuntimeException('stale');
        }

        return ['profileVersion' => $input['profileVersion'] + 1, 'coordinateStatus' => 'unverified', 'ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false], 'activationVersion' => (int) ($b['activationVersion'] ?? 0) + 1] + self::profileProjection($input['profile'], $b);
    }

    private static function slice(mixed $v, int $max): string
    {
        if (! is_string($v)) {
            return '';
        }
        if (strlen($v) <= $max) {
            return $v;
        }
        $utf16 = (string) mb_convert_encoding($v, 'UTF-16LE', 'UTF-8');

        return strlen($utf16) <= $max * 2 ? $v : (string) mb_convert_encoding(substr($utf16, 0, $max * 2), 'UTF-8', 'UTF-16LE');
    }

    /** @return list<string> */
    private static function list(mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }

        return array_map(static fn (string $s): string => self::slice($s, 200), array_slice(array_values(array_filter($v, 'is_string')), 0, 50));
    }

    /** new URL(...).href yaklasigi: yalniz http/https, kullanici bilgisi yok, sema/host kucuk, varsayilan port yok. */
    public static function safeHref(mixed $v, int $max = 2000): ?string
    {
        $text = self::slice($v, $max);
        if ($text === '' || ! Schemas::websiteAllowed($text)) {
            return null;
        }
        $u = parse_url($text);
        if (! is_array($u) || empty($u['host'])) {
            return null;
        }
        $scheme = strtolower((string) $u['scheme']);
        $port = isset($u['port']) && $u['port'] !== ($scheme === 'https' ? 443 : 80) ? ':'.$u['port'] : '';

        return $scheme.'://'.strtolower($u['host']).$port.($u['path'] ?? '/').(isset($u['query']) ? '?'.$u['query'] : '').(isset($u['fragment']) ? '#'.$u['fragment'] : '');
    }

    /** Salt okunur alt kume: rawSource ve ic kimlikler API'den cikmaz. */
    public static function catalogueEvidenceProjection(?array $source): ?array
    {
        if ($source === null) {
            return null;
        }
        $c = is_array($source['coordinateEvidence'] ?? null) ? $source['coordinateEvidence'] : [];
        $lat = $c['latitude'] ?? null;
        $lng = $c['longitude'] ?? null;
        $coordinates = (is_int($lat) || is_float($lat)) && is_finite((float) $lat) && abs($lat) <= 90 && (is_int($lng) || is_float($lng)) && is_finite((float) $lng) && abs($lng) <= 180 ? ['latitude' => $lat, 'longitude' => $lng] : null;
        $out = ['name' => self::slice($source['name'] ?? null, 1000), 'address' => self::slice($source['address'] ?? null, 5000), 'phone' => self::slice($source['phone'] ?? null, 1000)];
        $email = Contact::businessEmail($source['contactEmail'] ?? null);
        if ($email !== null) {
            $out['contactEmail'] = $email;
        }

        return $out + [
            'website' => self::safeHref($source['website'] ?? null),
            'source' => self::safeHref($source['source'] ?? null),
            'categoryCandidates' => self::list($source['categoryCandidates'] ?? null),
            'specialtyCandidates' => self::list($source['specialtyCandidates'] ?? null),
            'flags' => self::list($source['flags'] ?? null),
            'coordinates' => $coordinates,
            'coordinateSource' => self::safeHref($c['source'] ?? null),
            'coordinateNote' => self::slice($c['type'] ?? null, 1000),
            'quality' => self::qualityEvidenceProjection($source['rawSource'] ?? null),
        ];
    }

    private const QUALITY_FIELDS = ['currentness', 'address', 'coordinates', 'phone', 'email', 'website', 'google'];

    private const QUALITY_STATUSES = ['published-map-website-not-owner-verified', 'current-map-listing-not-operation-confirmation', 'owner-site-identity-matched', 'identity-not-established', 'map-permanently-closed-needs-review', 'ambiguous-branch-retained', 'legacy-not-reconfirmed', 'missing', 'published-map-number-matched-not-called', 'published-owner-number-matched-not-called', 'source-conflict-retained', 'filled-from-identity-linked-map-not-called', 'filled-from-owner-contact-not-called', 'updated-map-owner-consensus-not-called', 'identity-linked-map-address', 'updated-map-owner-address-consensus', 'legacy-province-mismatch', 'fresh-map-province-mismatch-rejected', 'map-pin-corroborated-within-150m', 'filled-identity-linked-map-pin', 'updated-map-owner-pin-consensus', 'identity-not-reconfirmed', 'parked-or-default-quarantined', 'unavailable-retained-for-review', 'published-business-contact-not-delivery-tested', 'multiple-published-business-contacts-review', 'multiple-published-business-contacts-primary-selected', 'not-found-in-parsed-business-contacts', 'not-verified-access-gap'];

    /** @return array{checkedAt:?string,internetAttempted:bool,googleVerified:false,statuses:list<array{field:string,status:string}>,sources:list<string>}|null */
    public static function qualityEvidenceProjection(mixed $rawSource): ?array
    {
        $review = is_array($rawSource) && is_array($rawSource['quality_review'] ?? null) ? $rawSource['quality_review'] : [];
        if (! in_array((string) (is_scalar($review['decision'] ?? null) ? $review['decision'] : ''), ['K165', 'K166'], true)) {
            return null;
        }
        $rawStatuses = is_array($review['statuses'] ?? null) ? $review['statuses'] : [];
        $statuses = [];
        foreach (self::QUALITY_FIELDS as $field) {
            $value = $rawStatuses[$field] ?? null;
            if (is_string($value) && in_array($value, self::QUALITY_STATUSES, true)) {
                $statuses[] = ['field' => $field, 'status' => $value];
            }
        }
        $sources = [];
        foreach (is_array($review['sources'] ?? null) ? $review['sources'] : [] as $v) {
            if (is_string($v) && strlen($v) <= 2000) {
                $href = self::safeHref($v, 2000);
                if ($href !== null) {
                    $sources[] = $href;
                }
            }
        }
        $at = $review['checkedAt'] ?? null;
        $checkedAt = is_string($at) && preg_match('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/D', $at) === 1 && strtotime($at) !== false ? $at : null;

        return ['checkedAt' => $checkedAt, 'internetAttempted' => ($review['internetAttempted'] ?? null) === true, 'googleVerified' => false, 'statuses' => $statuses, 'sources' => array_slice($sources, 0, 6)];
    }

    // -- catalogue-history.ts --------------------------------------------------

    private const KNOWN_HISTORY = ['source_import', 'catalogue_draft', 'catalogue_publication', 'profile_submitted', 'profile_reviewed'];

    private static function version(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 && $v <= 9007199254740991 ? $v : null;
    }

    public static function catalogueHistoryProjection(string $id, array $row, ?string $createdAt): array
    {
        $raw = is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : '';
        $kind = in_array($raw, self::KNOWN_HISTORY, true) ? $raw : 'other';
        $action = null;
        if ($kind === 'catalogue_publication' && in_array($row['action'] ?? null, ['publish', 'withdraw'], true)) {
            $action = $row['action'];
        } elseif ($kind === 'profile_reviewed' && in_array($row['decision'] ?? null, ['approve', 'reject'], true)) {
            $action = $row['decision'];
        }
        $checks = [];
        if ($kind === 'catalogue_publication' && $action === 'publish' && is_array($row['checks'] ?? null)) {
            foreach (['rights', 'identity', 'location', 'duplicates'] as $key) {
                $proof = $row['checks'][$key] ?? null;
                if (is_array($proof) && ($proof['checked'] ?? null) === true && is_string($proof['reference'] ?? null)) {
                    $checks[] = ['key' => $key, 'reference' => self::slice($proof['reference'], 800)];
                }
            }
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'action' => $action,
            'createdAt' => $createdAt !== null && $createdAt !== '' && strtotime($createdAt) !== false ? $createdAt : null,
            'reason' => $kind !== 'other' && is_string($row['reason'] ?? null) ? self::slice($row['reason'], 1000) : '',
            'profileVersion' => $kind === 'other' ? null : self::version($row['profileVersion'] ?? ($kind === 'profile_submitted' ? ($row['expectedVersion'] ?? null) : null)),
            'publicationVersion' => $kind === 'catalogue_publication' ? self::version($row['version'] ?? null) : null,
            'checks' => $checks,
        ];
    }

    // -- content-moderation.ts -------------------------------------------------

    public static function mayApplyAiResult(array $current, array $expected): bool
    {
        return ($current['status'] ?? null) === 'pending' && ($current['version'] ?? null) === $expected['version'] && ($current['text'] ?? null) === $expected['text'] && ($current['aiRunId'] ?? null) === $expected['runId'];
    }
}
