<?php

namespace App\Domain\Content;

use App\Support\Js;
use App\Support\ZodError;
use RuntimeException;

/**
 * packages/core saf kurallari. Girdiler kaynak belge anahtarlariyla dizilerdir (Docs),
 * olmayan alan dizide de yoktur; boylece `undefined` ve `??` anlamlari korunur.
 */
final class Policy
{
    private const SCORES = ['quality', 'payment', 'cleanliness', 'wcCleanliness'];

    private static function isUuid(mixed $v): bool
    {
        if (! is_string($v)) {
            return false;
        }
        try {
            Schemas::uuid()->parse($v);

            return true;
        } catch (ZodError) {
            return false;
        }
    }

    private static function isId(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $v) === 1;
    }

    // -- review-policy.ts --------------------------------------------------

    public static function affiliatedReviewer(string $uid, array $business, ?array $membership): bool
    {
        return ($business['ownerUid'] ?? null) === $uid || (($membership['active'] ?? null) === true && ($membership['userId'] ?? null) === $uid && in_array((string) ($membership['role'] ?? ''), ['owner', 'staff'], true));
    }

    /** @return array{status:string,delta:int} */
    public static function reviewTransition(mixed $status, string $decision): array
    {
        if ($status === 'pending' && $decision === 'approve') {
            return ['status' => 'approved', 'delta' => 1];
        }
        if ($status === 'pending' && $decision === 'reject') {
            return ['status' => 'rejected', 'delta' => 0];
        }
        if ($status === 'approved' && $decision === 'hide') {
            return ['status' => 'hidden', 'delta' => -1];
        }
        throw new RuntimeException('Bu yorumun durumu değişti. Listeyi yenile.');
    }

    /** @return array{reviewCount:int,reviewRatingSum:int} */
    public static function reviewTotals(mixed $count, mixed $sum, int|float $rating, int $delta): array
    {
        $c = $count ?? 0;
        $s = $sum ?? 0;
        if (! is_int($c) || ! is_int($s) || $c < 0 || $s < $c || $s > $c * 5 || ! is_int($rating) || $rating < 1 || $rating > 5) {
            throw new RuntimeException('Yorum sayaçları tutarsız.');
        }
        $nextCount = $c + $delta;
        $nextSum = $s + $rating * $delta;
        if ($nextCount < 0 || $nextSum < $nextCount || $nextSum > $nextCount * 5) {
            throw new RuntimeException('Yorum sayaçları tutarsız.');
        }

        return ['reviewCount' => $nextCount, 'reviewRatingSum' => $nextSum];
    }

    /** Kamu projeksiyonu: kullanici kimligi, e-posta veya moderasyon notu icermez. */
    public static function publicReview(string $id, array $v): array
    {
        $photos = self::photoIds($v['photoIds'] ?? $v['publicPhotoIds'] ?? null);
        $reply = self::publicReply($v['reply'] ?? null);
        $out = ['id' => $id];
        if ($photos !== []) {
            $out['photoIds'] = $photos;
        }
        if ($reply !== null) {
            $out['reply'] = $reply;
        }
        $out += ['rating' => $v['rating'] ?? null, 'text' => $v['text'] ?? null, 'resolved' => $v['resolved'] ?? null, 'wc' => $v['wc'] ?? null];
        foreach (self::SCORES as $k) {
            if (isset($v[$k]) && (is_int($v[$k]) || is_float($v[$k]))) {
                $out[$k] = $v[$k];
            }
        }

        return $out;
    }

    // -- review-replies.ts ---------------------------------------------------

    public static function publicReply(mixed $v): ?array
    {
        if (! is_array($v) || ! is_string($v['text'] ?? null)) {
            return null;
        }
        $n = Js::length($v['text']);

        return $n >= 10 && $n <= 2000 ? ['text' => $v['text']] : null;
    }

    public static function mayReply(string $uid, string $businessId, array $b, ?array $m): bool
    {
        return ($b['published'] ?? null) === true && ($b['sample'] ?? null) !== true && ($b['ownership'] ?? null) === 'verified'
            && ($m['active'] ?? null) === true && ($m['userId'] ?? null) === $uid && ($m['businessId'] ?? null) === $businessId
            && in_array((string) ($m['role'] ?? ''), ['owner', 'staff'], true);
    }

    public static function replyVersion(?array $previous, int $expected): int
    {
        if (($previous['version'] ?? 0) !== $expected || ($previous !== null && ! in_array((string) ($previous['status'] ?? ''), ['rejected', 'hidden'], true))) {
            throw new RuntimeException('Yanıt değişti veya zaten incelemede/yayında. Listeyi yenile.');
        }

        return $expected + 1;
    }

    // -- review-author-actions.ts / own-review.ts --------------------------

    /** @return array{canEdit:bool,canWithdraw:bool} */
    public static function authorReviewPermissions(array $v): array
    {
        return [
            'canEdit' => ($v['moderationHold'] ?? null) !== true && in_array((string) ($v['status'] ?? ''), ['pending', 'approved', 'rejected', 'withdrawn'], true),
            'canWithdraw' => in_array((string) ($v['status'] ?? ''), ['pending', 'approved', 'rejected', 'hidden'], true),
        ];
    }

    /** @return array{status:string,version:int,submissionVersion:int,delta:int,moderationHold:bool} */
    public static function authorReviewTransition(string $uid, string $businessId, ?array $v, int $expectedVersion, string $action): array
    {
        if ($v === null || ($v['userId'] ?? null) !== $uid || ($v['businessId'] ?? null) !== $businessId) {
            throw new RuntimeException('Yorum bulunamadı.');
        }
        if (($v['version'] ?? 1) !== $expectedVersion) {
            throw new RuntimeException('Yorum değişti. Güncel sürümü yükleyip yeniden dene.');
        }
        $permissions = self::authorReviewPermissions($v);
        if ($action === 'edit' ? ! $permissions['canEdit'] : ! $permissions['canWithdraw']) {
            throw new RuntimeException('Bu yorum için işlem kullanılamıyor.');
        }

        return [
            'status' => $action === 'edit' ? 'pending' : 'withdrawn',
            'version' => $expectedVersion + 1,
            'submissionVersion' => $expectedVersion + 1,
            'delta' => ($v['status'] ?? null) === 'approved' ? -1 : 0,
            'moderationHold' => ($v['moderationHold'] ?? null) === true || ($v['status'] ?? null) === 'hidden',
        ];
    }

    public static function ownReview(string $uid, string $businessId, string $id, ?array $v): ?array
    {
        if ($v === null || ($v['userId'] ?? null) !== $uid || ($v['businessId'] ?? null) !== $businessId) {
            return null;
        }
        $status = (string) ($v['status'] ?? '');
        $out = [
            'id' => $id,
            'text' => is_string($v['text'] ?? null) ? $v['text'] : '',
            'rating' => is_int($v['rating'] ?? null) ? $v['rating'] : null,
            'version' => is_int($v['version'] ?? null) ? $v['version'] : 1,
            'status' => in_array($status, ['pending', 'approved', 'rejected', 'hidden', 'withdrawn'], true) ? $status : 'unknown',
            'reason' => is_string($v['reason'] ?? null) ? $v['reason'] : '',
            'resolved' => is_string($v['resolved'] ?? null) ? $v['resolved'] : 'unknown',
            'wc' => is_string($v['wc'] ?? null) ? $v['wc'] : 'unknown',
        ];
        foreach (self::SCORES as $k) {
            if (isset($v[$k]) && (is_int($v[$k]) || is_float($v[$k]))) {
                $out[$k] = $v[$k];
            }
        }

        return $out + self::authorReviewPermissions($v);
    }

    // -- review-cases.ts -----------------------------------------------------

    public static function canFileCase(string $uid, string $kind, ?array $r): bool
    {
        if ($r === null) {
            return false;
        }

        return $kind === 'report'
            ? ($r['status'] ?? null) === 'approved' && ($r['userId'] ?? null) !== $uid
            : ($r['userId'] ?? null) === $uid && in_array((string) ($r['status'] ?? ''), ['rejected', 'hidden'], true);
    }

    public static function caseAction(string $uid, array $c, ?array $r, string $decision): string
    {
        if (($c['status'] ?? null) !== 'open') {
            throw new RuntimeException('Başvuru zaten sonuçlandı.');
        }
        if ($uid === ($c['userId'] ?? null) || $uid === ($r['userId'] ?? null) || (($c['kind'] ?? null) === 'appeal' && $uid === ($r['reviewedBy'] ?? null))) {
            throw new RuntimeException('Bu başvuruyu başka bir yönetici incelemeli.');
        }
        $stale = $r === null || ($r['businessId'] ?? null) !== ($c['businessId'] ?? null) || ($r['version'] ?? 1) !== ($c['reviewVersion'] ?? null);
        if ($decision === 'superseded') {
            if (! $stale) {
                throw new RuntimeException('İçerik sürümü değişmedi.');
            }

            return 'none';
        }
        if ($stale) {
            throw new RuntimeException('İçerik değişti; başvuruyu güncelliğini yitirmiş olarak kapat.');
        }
        if ($decision === 'dismiss') {
            return 'none';
        }
        if ($decision === 'remove' && ($c['kind'] ?? null) === 'report' && ($r['status'] ?? null) === 'approved') {
            return 'hide';
        }
        if ($decision === 'reopen' && ($c['kind'] ?? null) === 'appeal' && in_array((string) ($r['status'] ?? ''), ['rejected', 'hidden'], true)) {
            return 'pending';
        }
        throw new RuntimeException('Bu başvuru için karar kullanılamıyor.');
    }

    public static function caseView(string $id, array $v): array
    {
        return ['id' => $id, 'reviewId' => $v['reviewId'] ?? null, 'businessId' => $v['businessId'] ?? null, 'kind' => $v['kind'] ?? null, 'text' => $v['text'] ?? null, 'status' => $v['status'] ?? null, 'decision' => $v['decision'] ?? '', 'reason' => $v['reason'] ?? ''];
    }

    // -- review-photos.ts ----------------------------------------------------

    public static function photoSubmission(array $r): mixed
    {
        return $r['submissionVersion'] ?? 1;
    }

    public static function photoMatches(array $p, ?array $r): bool
    {
        return $r !== null && ($p['userId'] ?? null) === ($r['userId'] ?? null) && ($p['businessId'] ?? null) === ($r['businessId'] ?? null) && ($p['submissionVersion'] ?? null) === self::photoSubmission($r);
    }

    public static function photoCanUpload(string $uid, ?array $r): bool
    {
        return $r !== null && ($r['userId'] ?? null) === $uid && in_array((string) ($r['status'] ?? ''), ['pending', 'approved'], true);
    }

    public static function photoIsPublic(?array $p, ?array $r, ?array $b): bool
    {
        return $p !== null && self::photoMatches($p, $r) && ($p['status'] ?? null) === 'approved' && ($r['status'] ?? null) === 'approved' && ($b['published'] ?? null) === true && ($b['sample'] ?? null) !== true;
    }

    /** @return list<string> */
    public static function photoIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_filter($value, [self::class, 'isUuid'])), 0, 3);
    }

    // photoMayPublish / photoCleanupDue yalniz admin ve temizlik isinde; kosullar kaynakla birebir.
    public static function photoMayPublish(array $p, ?array $r, ?array $b, ?array $view, bool $attested, int $now): bool
    {
        return $attested && ($p['status'] ?? null) === 'pending' && self::photoMatches($p, $r) && ($r['status'] ?? null) === 'approved' && ($b['published'] ?? null) === true && ($b['sample'] ?? null) !== true && is_int($p['expiresAtMs'] ?? null) && $p['expiresAtMs'] > $now && is_string($p['sha256'] ?? null) && ($view['sha256'] ?? null) === $p['sha256'];
    }

    public static function photoCleanupDue(array $p, int $now): bool
    {
        $status = (string) ($p['status'] ?? '');

        return $status === 'purging'
            || (in_array($status, ['uploading', 'failed'], true) && is_int($p['createdAtMs'] ?? null) && $p['createdAtMs'] + 86400000 <= $now)
            || (in_array($status, ['pending', 'rejected', 'withdrawn', 'hidden'], true) && is_int($p['expiresAtMs'] ?? null) && $p['expiresAtMs'] <= $now);
    }

    // -- claim-status.ts / claim-evidence.ts --------------------------------

    public static function ownClaimView(string $id, array $d): array
    {
        return [
            'id' => $id,
            'name' => $d['name'] ?? null,
            'city' => $d['city'] ?? null,
            'address' => $d['address'] ?? null,
            'status' => $d['status'] ?? null,
            'reviewReason' => is_string($d['reviewReason'] ?? null) ? $d['reviewReason'] : '',
            'businessId' => is_string($d['businessId'] ?? null) ? $d['businessId'] : null,
            'reply' => is_string($d['reply'] ?? null) ? $d['reply'] : '',
        ];
    }

    public static function canReplyToClaim(string $uid, ?array $claim): bool
    {
        return $claim !== null && ($claim['userId'] ?? null) === $uid && ($claim['status'] ?? null) === 'needs_information';
    }

    public static function canReadEvidence(string $uid, bool $admin, bool $verified, ?array $claim): bool
    {
        return $verified && $claim !== null && (($claim['userId'] ?? null) === $uid || $admin);
    }

    public static function canUploadEvidence(string $uid, bool $verified, ?array $claim): bool
    {
        return $verified && $claim !== null && ($claim['userId'] ?? null) === $uid && in_array((string) ($claim['status'] ?? ''), ['submitted', 'needs_information'], true);
    }

    public static function reviewableEvidence(?array $e, string $claimId, string $userId, ?array $view, string $reviewerId, int $now): bool
    {
        return $e !== null && ($e['claimId'] ?? null) === $claimId && ($e['userId'] ?? null) === $userId && ($e['status'] ?? null) === 'ready' && is_int($e['expiresAtMs'] ?? null) && $e['expiresAtMs'] > $now && $view !== null && ($view['reviewerId'] ?? null) === $reviewerId && ($view['sha256'] ?? null) === ($e['sha256'] ?? null);
    }

    public static function currentEvidenceProof(?array $proof, array $claim, int $now): bool
    {
        return $proof !== null && is_int($claim['evidenceRevision'] ?? null) && ($proof['evidenceRevision'] ?? null) === $claim['evidenceRevision'] && ($claim['pendingEvidenceUploads'] ?? 0) === 0 && is_int($proof['expiresAtMs'] ?? null) && $proof['expiresAtMs'] > $now;
    }

    // -- business-management.ts / business-console-client.ts ----------------

    public static function mayEditOwnedProfile(string $uid, array $business, ?array $membership): bool
    {
        return ($business['ownership'] ?? null) === 'verified' && ($membership['active'] ?? null) === true && ($membership['userId'] ?? null) === $uid && in_array((string) ($membership['role'] ?? ''), ['owner', 'staff'], true);
    }

    public static function ownsCampaignBusiness(string $uid, string $bid, array $b, ?array $m): bool
    {
        return ($b['sample'] ?? null) !== true && ($b['ownership'] ?? null) === 'verified' && ($m['active'] ?? null) === true && ($m['userId'] ?? null) === $uid && ($m['businessId'] ?? null) === $bid && ($m['role'] ?? null) === 'owner';
    }

    public static function consoleMembershipId(string $uid, string $docId, array $m): ?string
    {
        $id = $m['businessId'] ?? null;

        return self::isId($id) && $docId === $uid.'_'.$id && ($m['userId'] ?? null) === $uid && ($m['active'] ?? null) === true && in_array((string) ($m['role'] ?? ''), ['owner', 'staff'], true) ? $id : null;
    }

    /** @param bool|'activation'|'campaigns' $profile */
    public static function projectConsoleBranch(string $uid, string $docId, array $m, array $b, bool|string $profile = false): ?array
    {
        $businessId = self::consoleMembershipId($uid, $docId, $m);
        if ($businessId === null) {
            return null;
        }
        $allowed = match (true) {
            $profile === 'campaigns' => self::ownsCampaignBusiness($uid, $businessId, $b, $m),
            $profile === 'activation' => ($b['sample'] ?? null) !== true && self::activationOwner($uid, $businessId, $b, $m),
            $profile === true => ($b['sample'] ?? null) !== true && self::mayEditOwnedProfile($uid, $b, $m),
            default => self::mayReply($uid, $businessId, $b, $m),
        };
        if (! $allowed) {
            return null;
        }
        try {
            return Schemas::consoleBranch()->parse((object) ['id' => $businessId, 'name' => $b['name'] ?? null, 'city' => $b['city'] ?? '', 'district' => $b['district'] ?? '', 'role' => $m['role'] ?? null]);
        } catch (ZodError) {
            return null;
        }
    }

    /** projectManagedProfile; salt okuma, eski eksik profiller kabul. */
    public static function projectManagedProfile(string $uid, string $docId, array $m, array $b, ?array $change = null): ?array
    {
        $businessId = self::consoleMembershipId($uid, $docId, $m);
        if ($businessId === null || ($b['sample'] ?? null) === true || ! self::mayEditOwnedProfile($uid, $b, $m)) {
            return null;
        }
        $fields = [];
        foreach (['name', 'city', 'district', 'address', 'phone', 'website', 'hours', 'description'] as $k) {
            $fields[$k] = $b[$k] ?? '';
        }
        if (is_string($b['contactEmail'] ?? null)) {
            $fields['contactEmail'] = $b['contactEmail'];
        }
        $location = null;
        if (is_int($b['latitude'] ?? null) || is_float($b['latitude'] ?? null)) {
            try {
                $location = Schemas::location()->parse((object) ['latitude' => $b['latitude'], 'longitude' => $b['longitude'] ?? null]);
            } catch (ZodError) {
                $location = null;
            }
        }
        $input = $fields + [
            'categories' => $b['categories'] ?? [],
            'services' => $b['services'] ?? [],
            'brands' => $b['brands'] ?? [],
            'location' => $location === null ? null : (object) $location,
            'id' => $businessId,
            'role' => $m['role'] ?? null,
            'version' => $b['profileVersion'] ?? 0,
            'change' => $change === null ? null : (object) ['revisionId' => $change['revisionId'] ?? null, 'status' => $change['status'] ?? null, 'profile' => self::toObject($change['profile'] ?? null), 'reason' => $change['reason'] ?? ''],
        ];
        try {
            return Schemas::managedProfile()->parse((object) $input);
        } catch (ZodError) {
            return null;
        }
    }

    private static function toObject(mixed $v): mixed
    {
        if (is_array($v) && ! array_is_list($v)) {
            return (object) array_map([self::class, 'toObject'], $v);
        }

        return $v;
    }

    // -- feature-activation.ts / activation-client.ts ------------------------

    public static function activationOwner(string $uid, string $bid, array $b, ?array $m): bool
    {
        return ($b['ownerUid'] ?? null) === $uid && ($b['ownership'] ?? null) === 'verified' && ($m['userId'] ?? null) === $uid && ($m['businessId'] ?? null) === $bid && ($m['active'] ?? null) === true && ($m['role'] ?? null) === 'owner';
    }

    public static function mayManageActivation(string $uid, string $bid, ?array $b, ?array $m): bool
    {
        return $b !== null && ($b['sample'] ?? null) !== true && self::activationOwner($uid, $bid, $b, $m);
    }

    public static function projectActivation(string $uid, string $bid, array $b, ?array $m, ?array $r = null): ?array
    {
        if (! self::mayManageActivation($uid, $bid, $b, $m)) {
            return null;
        }
        $request = null;
        if ($r !== null) {
            $request = ['status' => $r['status'] ?? null];
            foreach (['features', 'monitorInbox', 'capacityReady'] as $k) {
                if (array_key_exists($k, $r)) {
                    $request[$k] = $r[$k];
                }
            }
            $request['reason'] = $r['reason'] ?? '';
        }
        $features = $b['features'] ?? ['quotes' => false, 'appointments' => false];
        try {
            $parsed = Schemas::activationView()->parse((object) [
                'id' => $bid,
                'name' => $b['name'] ?? null,
                'version' => $b['activationVersion'] ?? 0,
                'published' => ($b['published'] ?? null) === true,
                'features' => (object) $features,
                'ownerReady' => ($b['ownerReady'] ?? null) === true,
                'request' => $request === null ? null : (object) array_map(static fn (mixed $x): mixed => is_array($x) ? (object) $x : $x, $request),
            ]);
        } catch (ZodError) {
            return null;
        }

        return $parsed;
    }

    /**
     * @param  array{uid:string,owner:bool,admin:bool,affiliated:bool,applicantStillOwner:bool}  $who
     * @return array{version:int,status:string,business:array<string,mixed>}
     */
    public static function activationTransition(array $b, ?array $previous, array $a, array $who): array
    {
        $action = $a['action'];
        $permitted = match ($action) {
            'request' => $who['owner'],
            'pause' => $who['owner'] || ($who['admin'] && ! $who['affiliated']),
            default => $who['admin'] && ! $who['affiliated'] && ($previous['applicantId'] ?? null) !== $who['uid'],
        };
        if (! $permitted) {
            throw new RuntimeException('forbidden');
        }
        if (($b['activationVersion'] ?? 0) !== $a['version']) {
            throw new RuntimeException('stale');
        }
        $version = $a['version'] + 1;
        if ($action === 'pause') {
            if (! $who['owner'] && (! $who['admin'] || $who['affiliated'])) {
                throw new RuntimeException('forbidden');
            }

            return ['version' => $version, 'status' => 'paused', 'business' => ['activationVersion' => $version, 'ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false]]];
        }
        if ($action === 'request') {
            if (! $who['owner'] || ($b['sample'] ?? false) || ! ($b['published'] ?? false) || ($b['ownership'] ?? null) !== 'verified') {
                throw new RuntimeException('forbidden');
            }

            return ['version' => $version, 'status' => 'pending', 'business' => ['activationVersion' => $version]];
        }
        if (! $who['admin'] || $who['affiliated'] || ($previous['applicantId'] ?? null) === $who['uid']) {
            throw new RuntimeException('forbidden');
        }
        if (($previous['status'] ?? null) !== 'pending') {
            throw new RuntimeException('stale');
        }
        if ($action === 'approve') {
            $features = $previous['features'] ?? [];
            if (! $who['applicantStillOwner'] || ($b['sample'] ?? false) || ! ($b['published'] ?? false) || ($b['ownership'] ?? null) !== 'verified' || ! ($previous['monitorInbox'] ?? false) || ! ($previous['capacityReady'] ?? false) || ! (($features['quotes'] ?? false) || ($features['appointments'] ?? false))) {
                throw new RuntimeException('ineligible');
            }

            return ['version' => $version, 'status' => 'approved', 'business' => ['activationVersion' => $version, 'ownerReady' => true, 'features' => $features]];
        }

        return ['version' => $version, 'status' => 'rejected', 'business' => ['activationVersion' => $version]];
    }

    // -- business-photos.ts -------------------------------------------------

    public const BUSINESS_PHOTO_SLOTS = 8;

    public const BUSINESS_PHOTO_ATTEMPTS = 40;

    public const BUSINESS_PHOTO_WINDOW_MS = 30 * 86400000;

    /** @return list<string> */
    public static function businessPhotoIds(mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_values(array_filter($v, [self::class, 'isUuid'])))), 0, 8);
    }

    public static function businessPhotoOwner(string $uid, string $bid, ?array $b, ?array $m): bool
    {
        return $b !== null && ($b['sample'] ?? null) !== true && self::activationOwner($uid, $bid, $b, $m);
    }

    public static function businessPhotoPublic(?array $p, ?array $b, ?array $m): bool
    {
        return $p !== null && $b !== null && ($p['status'] ?? null) === 'approved' && ($b['published'] ?? null) === true && is_string($p['ownerUid'] ?? null) && is_string($p['businessId'] ?? null) && self::businessPhotoOwner($p['ownerUid'], $p['businessId'], $b, $m) && in_array((string) ($p['id'] ?? ''), self::businessPhotoIds($b['publicBusinessPhotoIds'] ?? null), true);
    }

    public static function businessPhotoAttempts(array $b, int $now): int
    {
        $w = $b['photoAttemptWindow'] ?? null;
        $a = $b['photoAttempts'] ?? null;

        return is_int($w) && $w <= $now && $now - $w < self::BUSINESS_PHOTO_WINDOW_MS && is_int($a) && $a >= 0 ? $a : 0;
    }

    public static function businessPhotoReadable(array $p, int $now): bool
    {
        $status = (string) ($p['status'] ?? '');

        return in_array($status, ['pending', 'approved', 'rejected', 'hidden'], true) && ($status === 'approved' || (is_int($p['expiresAtMs'] ?? null) && $p['expiresAtMs'] > $now));
    }

    public static function businessPhotoCleanupDue(array $p, int $now): bool
    {
        return self::photoCleanupDue($p, $now);
    }

    /** @return array{businessPhotoSlots:list<string>,photoAttempts:int,photoAttemptWindow:mixed} */
    public static function businessPhotoReservation(array $b, string $id, int $now): array
    {
        if (! self::isUuid($id)) {
            throw new RuntimeException('photo_id');
        }
        $slots = self::businessPhotoIds($b['businessPhotoSlots'] ?? null);
        $attempts = self::businessPhotoAttempts($b, $now);
        if (in_array($id, $slots, true) || count($slots) >= 8 || $attempts >= 40) {
            throw new RuntimeException('photo_capacity');
        }

        return ['businessPhotoSlots' => [...$slots, $id], 'photoAttempts' => $attempts + 1, 'photoAttemptWindow' => $attempts === 0 ? $now : ($b['photoAttemptWindow'] ?? null)];
    }

    public static function businessPhotoUnlink(array $b, string $id, bool $release): array
    {
        $ids = array_values(array_filter(self::businessPhotoIds($b['publicBusinessPhotoIds'] ?? null), static fn (string $v): bool => $v !== $id));
        $out = ['publicBusinessPhotoIds' => $ids, 'businessCoverPhotoId' => ($b['businessCoverPhotoId'] ?? null) === $id ? ($ids[0] ?? null) : ($b['businessCoverPhotoId'] ?? null)];
        if ($release) {
            $out['businessPhotoSlots'] = array_values(array_filter(self::businessPhotoIds($b['businessPhotoSlots'] ?? null), static fn (string $v): bool => $v !== $id));
        }

        return $out;
    }

    public static function businessPhotoMayApprove(array $p, ?array $b, ?array $m, ?array $view, bool $attested, int $now): bool
    {
        return $b !== null && ($b['published'] ?? null) === true && is_string($p['ownerUid'] ?? null) && is_string($p['businessId'] ?? null) && self::businessPhotoOwner($p['ownerUid'], $p['businessId'], $b, $m) && ($p['rightsAttested'] ?? null) === true && ($p['status'] ?? null) === 'pending' && self::businessPhotoReadable($p, $now) && $attested && is_string($p['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $p['sha256']) === 1 && ($view['sha256'] ?? null) === $p['sha256'] && in_array((string) ($p['id'] ?? ''), self::businessPhotoIds($b['businessPhotoSlots'] ?? null), true);
    }

    public static function businessPhotoReviewer(string $uid, mixed $admin, array $p, ?array $b, ?array $m): bool
    {
        return $admin === true && ($p['ownerUid'] ?? null) !== $uid && ($b['ownerUid'] ?? null) !== $uid && ($m['active'] ?? null) !== true;
    }

    public static function businessPhotoPrivateOwner(string $uid, array $p, ?array $b, ?array $m): bool
    {
        return ($p['ownerUid'] ?? null) === $uid && is_string($p['businessId'] ?? null) && self::businessPhotoOwner($uid, $p['businessId'], $b, $m);
    }
}
