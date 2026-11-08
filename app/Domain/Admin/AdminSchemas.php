<?php

namespace App\Domain\Admin;

use App\Domain\Content\Schemas;
use App\Support\Z;

/** Admin Zod semalari: review-policy, review-cases, review-photos, business-photos, claim-*, catalogue-*. */
final class AdminSchemas
{
    private static function reason(): Z
    {
        return Z::string()->trim()->min(10)->max(1000);
    }

    /** reviewDecisionSchema */
    public static function reviewDecision(): Z
    {
        return Z::strictObject([
            'id' => Schemas::id(),
            'version' => Z::number()->int()->positive(),
            'decision' => Z::enum(['approve', 'reject', 'hide']),
            'reason' => self::reason(),
            'contentReviewed' => Z::boolean()->optional(),
        ]);
    }

    public static function caseDecision(): Z
    {
        return Z::strictObject([
            'id' => Schemas::id(),
            'decision' => Z::enum(['dismiss', 'remove', 'reopen', 'superseded']),
            'reason' => self::reason(),
            'reviewed' => Z::literal(true),
        ]);
    }

    public static function reviewPhotoDecision(): Z
    {
        return Z::strictObject([
            'id' => Schemas::uuid(),
            'version' => Z::number()->int()->positive(),
            'decision' => Z::enum(['approve', 'reject', 'hide']),
            'reason' => self::reason(),
            'attested' => Z::boolean(),
        ]);
    }

    public static function businessPhotoDecision(): Z
    {
        return Z::strictObject([
            'id' => Schemas::uuid(),
            'version' => Z::number()->int()->positive()->max(9007199254740991),
            'decision' => Z::enum(['approve', 'reject', 'hide']),
            'reason' => self::reason(),
            'attested' => Z::boolean(),
        ]);
    }

    public static function analyze(): Z
    {
        return Z::strictObject([
            'id' => Schemas::id(),
            'version' => Z::number()->int()->positive(),
            'kind' => Z::enum(['review', 'reply'])->default('review'),
        ]);
    }

    public static function claimReview(): Z
    {
        $other = Z::strictObject(['claimId' => Schemas::id(), 'decision' => Z::enum(['request_information', 'reject']), 'reason' => self::reason()]);

        return Z::discriminatedUnion('decision', [
            'approve' => Z::strictObject(['claimId' => Schemas::id(), 'decision' => Z::literal('approve'), 'reason' => self::reason(), 'evidenceChecked' => Z::literal(true), 'verificationReference' => Schemas::id()]),
            'request_information' => $other,
            'reject' => $other,
        ]);
    }

    public static function evidenceVerification(): Z
    {
        return Z::strictObject([
            'claimId' => Schemas::id(),
            'evidenceIds' => Z::array(Schemas::uuid())->min(1)->max(5)->refine(static fn (array $ids): bool => count(array_unique($ids)) === count($ids)),
            'evidenceRevision' => Z::number()->int()->positive(),
            'attested' => Z::literal(true),
            'reason' => self::reason(),
        ]);
    }

    public static function claimTarget(): Z
    {
        $common = ['claimId' => Schemas::id(), 'reason' => self::reason(), 'identityChecked' => Z::literal(true)];

        return Z::discriminatedUnion('mode', [
            'existing' => Z::strictObject($common + ['mode' => Z::literal('existing'), 'businessId' => Schemas::id()]),
            'new' => Z::strictObject($common + ['mode' => Z::literal('new'), 'duplicateChecked' => Z::literal(true), 'profile' => Schemas::businessProfile()]),
        ]);
    }

    public static function profileDecision(): Z
    {
        return Z::strictObject([
            'businessId' => Schemas::id(),
            'revisionId' => Schemas::uuid(),
            'decision' => Z::enum(['approve', 'reject']),
            'reason' => self::reason(),
        ]);
    }

    public static function publicationAction(): Z
    {
        $proof = static fn (): Z => Z::strictObject(['checked' => Z::literal(true), 'reference' => Z::string()->trim()->min(10)->max(800)]);
        $base = ['businessId' => Schemas::id(), 'version' => Z::number()->int()->nonnegative(), 'profileVersion' => Z::number()->int()->nonnegative(), 'reason' => self::reason()];

        return Z::discriminatedUnion('action', [
            'publish' => Z::strictObject($base + ['action' => Z::literal('publish'), 'checks' => Z::strictObject(['rights' => $proof(), 'identity' => $proof(), 'location' => $proof(), 'duplicates' => $proof()])]),
            'withdraw' => Z::strictObject($base + ['action' => Z::literal('withdraw')]),
        ]);
    }

    public static function catalogueDraft(): Z
    {
        return Z::strictObject([
            'businessId' => Schemas::id(),
            'profileVersion' => Z::number()->int()->nonnegative(),
            'publicationVersion' => Z::number()->int()->nonnegative(),
            'reason' => self::reason(),
            'profile' => Schemas::businessProfile(),
        ]);
    }

    public static function catalogueHistoryQuery(): Z
    {
        return Z::strictObject(['businessId' => Schemas::id(), 'after' => Schemas::id()->optional()]);
    }
}
