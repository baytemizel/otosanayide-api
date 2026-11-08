<?php

namespace App\Domain\Content;

use Illuminate\Support\Facades\DB;
use stdClass;

/** Satir -> kaynak belge bicimi. Bos kolon anahtar olarak yer almaz (`undefined` karsiligi). */
final class Docs
{
    /** @param array<string,mixed> $v */
    private static function compact(array $v): array
    {
        return array_filter($v, static fn (mixed $x): bool => $x !== null);
    }

    private static function int(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    private static function bool(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }

    /** @return list<string> */
    public static function refs(string $table, string $column, string $id, ?string $kind = null): array
    {
        $q = DB::table($table)->where($column, $id);
        if ($kind !== null) {
            $q->where('kind', $kind);
        }

        return $q->orderBy('position')->pluck('photo_id')->all();
    }

    public static function membership(?stdClass $m): ?array
    {
        return $m === null ? null : ['userId' => $m->user_id, 'businessId' => $m->business_id, 'role' => $m->role, 'active' => (bool) $m->active];
    }

    public static function review(?stdClass $r): ?array
    {
        if ($r === null) {
            return null;
        }

        return self::compact([
            'userId' => $r->user_id,
            'businessId' => $r->business_id,
            'rating' => (int) $r->rating,
            'quality' => self::int($r->quality),
            'payment' => self::int($r->payment),
            'cleanliness' => self::int($r->cleanliness),
            'wcCleanliness' => self::int($r->wc_cleanliness),
            'resolved' => $r->resolved,
            'wc' => $r->wc,
            'text' => $r->text,
            'status' => $r->status,
            'version' => (int) $r->version,
            'submissionVersion' => (int) $r->submission_version,
            'moderationHold' => (bool) $r->moderation_hold,
            'reason' => $r->reason,
            'reviewedBy' => $r->reviewed_by,
            'photoAttempts' => (int) $r->photo_attempts,
        ]) + [
            'photoSlots' => self::refs('review_photo_refs', 'review_id', $r->id, 'slot'),
            'publicPhotoIds' => self::refs('review_photo_refs', 'review_id', $r->id, 'public'),
        ];
    }

    public static function publicReview(stdClass $p): array
    {
        return self::compact([
            'rating' => (int) $p->rating,
            'quality' => self::int($p->quality),
            'payment' => self::int($p->payment),
            'cleanliness' => self::int($p->cleanliness),
            'wcCleanliness' => self::int($p->wc_cleanliness),
            'resolved' => $p->resolved,
            'wc' => $p->wc,
            'text' => $p->text,
            'reply' => $p->reply_text === null ? null : ['text' => $p->reply_text],
        ]) + [
            'photoIds' => DB::table('public_review_photos')->where('business_id', $p->business_id)->where('review_id', $p->review_id)->orderBy('position')->pluck('photo_id')->all(),
        ];
    }

    public static function reply(?stdClass $r): ?array
    {
        return $r === null ? null : self::compact(['businessId' => $r->business_id, 'reviewId' => $r->review_id, 'userId' => $r->user_id, 'text' => $r->text, 'status' => $r->status, 'version' => (int) $r->version, 'reason' => $r->reason]);
    }

    public static function business(?stdClass $b): ?array
    {
        if ($b === null) {
            return null;
        }
        $id = $b->id;

        return self::compact([
            'name' => $b->name,
            'city' => $b->city,
            'district' => $b->district,
            'address' => $b->address,
            'phone' => $b->phone,
            'contactEmail' => $b->contact_email,
            'website' => $b->website,
            'hours' => $b->hours,
            'description' => $b->description,
            'latitude' => $b->latitude === null ? null : (float) $b->latitude,
            'longitude' => $b->longitude === null ? null : (float) $b->longitude,
            'coordinateStatus' => $b->coordinate_status,
            'published' => (bool) $b->published,
            'sample' => (bool) $b->sample,
            'ownership' => $b->ownership,
            'ownerUid' => $b->owner_uid,
            'ownerReady' => (bool) $b->owner_ready,
            'features' => ['quotes' => (bool) $b->feature_quotes, 'appointments' => (bool) $b->feature_appointments],
            'businessCoverPhotoId' => $b->business_cover_photo_id,
            'profileVersion' => (int) $b->profile_version,
            'activationVersion' => (int) $b->activation_version,
            'reviewCount' => (int) $b->review_count,
            'reviewRatingSum' => (int) $b->review_rating_sum,
            'photoAttempts' => (int) $b->photo_attempts,
            'photoAttemptWindow' => self::int($b->photo_attempt_window),
        ]) + [
            'publicBusinessPhotoIds' => self::refs('business_public_photos', 'business_id', $id),
            'businessPhotoSlots' => self::refs('business_photo_slots', 'business_id', $id),
            'categories' => self::businessValues($id, 'category'),
            'services' => self::businessValues($id, 'service'),
            'brands' => self::businessValues($id, 'brand'),
        ];
    }

    /** @return list<string> */
    private static function businessValues(string $id, string $kind): array
    {
        return DB::table('business_values')->where('business_id', $id)->where('kind', $kind)->orderBy('position')->pluck('value')->all();
    }

    public static function claim(?stdClass $c): ?array
    {
        if ($c === null) {
            return null;
        }

        return self::compact([
            'userId' => $c->user_id,
            'businessId' => $c->business_id,
            'name' => $c->name,
            'city' => $c->city,
            'address' => $c->address,
            'status' => $c->status,
            'reply' => $c->reply,
            'reviewReason' => $c->review_reason,
            'evidenceRevision' => self::int($c->evidence_revision),
            'evidenceCount' => (int) $c->evidence_count,
            'pendingEvidenceUploads' => (int) $c->pending_evidence_uploads,
            'totalEvidenceUploads' => (int) $c->total_evidence_uploads,
        ]);
    }

    public static function evidence(?stdClass $e): ?array
    {
        if ($e === null) {
            return null;
        }

        return self::compact([
            'claimId' => $e->claim_id,
            'userId' => $e->user_id,
            'kind' => $e->kind,
            'status' => $e->status,
            'bytes' => self::int($e->bytes),
            'width' => self::int($e->width),
            'height' => self::int($e->height),
            'sha256' => $e->sha256,
            'expiresAtMs' => self::int($e->expires_at_ms),
        ]);
    }

    public static function reviewPhoto(?stdClass $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return self::compact([
            'reviewId' => $p->review_id,
            'businessId' => $p->business_id,
            'userId' => $p->user_id,
            'submissionVersion' => (int) $p->submission_version,
            'version' => (int) $p->version,
            'status' => $p->status,
            'rightsAttested' => self::bool($p->rights_attested),
            'uploadNonce' => $p->upload_nonce,
            'createdAtMs' => (int) $p->created_at_ms,
            'width' => self::int($p->width),
            'height' => self::int($p->height),
            'bytes' => self::int($p->bytes),
            'thumbnailBytes' => self::int($p->thumbnail_bytes),
            'sha256' => $p->sha256,
            'thumbnailSha256' => $p->thumbnail_sha256,
            'expiresAtMs' => self::int($p->expires_at_ms),
            'reason' => $p->reason,
        ]);
    }

    public static function businessPhoto(?stdClass $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return self::compact([
            'id' => $p->id,
            'businessId' => $p->business_id,
            'ownerUid' => $p->owner_uid,
            'status' => $p->status,
            'version' => (int) $p->version,
            'rightsAttested' => self::bool($p->rights_attested),
            'uploadNonce' => $p->upload_nonce,
            'createdAtMs' => (int) $p->created_at_ms,
            'width' => self::int($p->width),
            'height' => self::int($p->height),
            'bytes' => self::int($p->bytes),
            'thumbnailBytes' => self::int($p->thumbnail_bytes),
            'sha256' => $p->sha256,
            'thumbnailSha256' => $p->thumbnail_sha256,
            'expiresAtMs' => self::int($p->expires_at_ms),
            'reason' => $p->reason,
        ]);
    }

    public static function activation(?stdClass $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $features = $r->feature_quotes === null && $r->feature_appointments === null ? null : ['quotes' => (bool) $r->feature_quotes, 'appointments' => (bool) $r->feature_appointments];

        return self::compact([
            'applicantId' => $r->applicant_id,
            'status' => $r->status,
            'version' => (int) $r->version,
            'features' => $features,
            'monitorInbox' => self::bool($r->monitor_inbox),
            'capacityReady' => self::bool($r->capacity_ready),
            'reason' => $r->reason,
        ]);
    }

    /** businessChanges alanlari + `profile` nesnesi. */
    public static function change(?stdClass $c): ?array
    {
        if ($c === null) {
            return null;
        }
        $values = static fn (string $kind): array => DB::table('business_change_values')->where('business_id', $c->business_id)->where('kind', $kind)->orderBy('position')->pluck('value')->all();
        $profile = ['name' => $c->profile_name, 'city' => $c->profile_city, 'district' => $c->profile_district, 'address' => $c->profile_address, 'phone' => $c->profile_phone, 'website' => $c->profile_website];
        if ($c->profile_contact_email !== null) {
            $profile['contactEmail'] = $c->profile_contact_email;
        }
        $profile += [
            'hours' => $c->profile_hours,
            'description' => $c->profile_description,
            'categories' => $values('category'),
            'services' => $values('service'),
            'brands' => $values('brand'),
            'location' => $c->profile_latitude === null || $c->profile_longitude === null ? null : ['latitude' => (float) $c->profile_latitude, 'longitude' => (float) $c->profile_longitude],
        ];

        return self::compact(['revisionId' => $c->revision_id, 'status' => $c->status, 'userId' => $c->user_id, 'expectedVersion' => (int) $c->expected_version, 'reason' => $c->reason]) + ['profile' => $profile];
    }
}
