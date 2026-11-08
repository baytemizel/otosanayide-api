<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\CaseModeration;
use App\Domain\Admin\Moderator;
use App\Domain\Content\Schemas;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReviewCaseAdminController
{
    public function index(Request $r): JsonResponse
    {
        Moderator::admin($r);
        $after = Query::from($r)->get('after');

        return Output::noStore(CaseModeration::page(($after !== null && $after !== '') ? Schemas::id()->parse($after) : null));
    }

    public function update(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);
        CaseModeration::decide($u, AdminSchemas::caseDecision()->parse(Body::json($r)));

        return Output::bare(['ok' => true]);
    }
}
