<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\ClaimAdmin;
use App\Domain\Admin\ClaimReviewConflict;
use App\Domain\Admin\Moderator;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClaimAdminController
{
    public function index(Request $r): JsonResponse
    {
        Moderator::admin($r);

        return Output::noStore(ClaimAdmin::open());
    }

    public function update(Request $r): JsonResponse
    {
        try {
            $u = Moderator::admin($r, Moderator::REQUIRED_VERIFIED);

            return Output::noStore(ClaimAdmin::decide($u, AdminSchemas::claimReview()->parse(Body::json($r))));
        } catch (ClaimReviewConflict $e) {
            throw new HttpError(409, $e->getMessage());
        }
    }

    public function verifyEvidence(Request $r): JsonResponse
    {
        $u = Moderator::admin($r, Moderator::REQUIRED_VERIFIED);
        ClaimAdmin::assertEvidenceStore();

        return Output::noStore(['verificationReference' => ClaimAdmin::verifyEvidence($u, AdminSchemas::evidenceVerification()->parse(Body::json($r)))]);
    }

    public function target(Request $r): JsonResponse
    {
        Moderator::admin($r, Moderator::REQUIRED_VERIFIED);

        return Output::noStore(ClaimAdmin::target(Schemas::id()->parse(Query::from($r)->get('businessId'))));
    }

    public function mapTarget(Request $r): JsonResponse
    {
        $u = Moderator::admin($r, Moderator::REQUIRED_VERIFIED);

        return Output::noStore(ClaimAdmin::mapTarget($u, AdminSchemas::claimTarget()->parse(Body::json($r))));
    }
}
