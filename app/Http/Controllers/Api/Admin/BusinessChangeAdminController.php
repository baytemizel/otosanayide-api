<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\BusinessChangeReview;
use App\Domain\Admin\Moderator;
use App\Support\Body;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BusinessChangeAdminController
{
    public function index(Request $r): JsonResponse
    {
        Moderator::admin($r);

        return Output::noStore(BusinessChangeReview::pending());
    }

    public function update(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);
        BusinessChangeReview::decide($u, AdminSchemas::profileDecision()->parse(Body::json($r)));

        return Output::bare(['ok' => true]);
    }
}
