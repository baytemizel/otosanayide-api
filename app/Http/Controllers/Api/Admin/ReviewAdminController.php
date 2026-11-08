<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\AiAnalysis;
use App\Domain\Admin\Moderator;
use App\Domain\Admin\ReviewModeration;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReviewAdminController
{
    public function index(Request $r): JsonResponse
    {
        Moderator::admin($r);
        $q = Query::from($r);
        $status = $q->get('status') ?? 'pending';
        if (! in_array($status, ['pending', 'approved'], true)) {
            throw new HttpError(400, 'Geçersiz inceleme durumu.');
        }
        $after = $q->get('after');
        $cursor = ($after !== null && $after !== '') ? Schemas::id()->parse($after) : null;

        return Output::noStore(ReviewModeration::page($status, $cursor));
    }

    public function update(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);
        ReviewModeration::decide($u, AdminSchemas::reviewDecision()->parse(Body::json($r)));

        return Output::bare(['ok' => true]);
    }

    public function analyze(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);
        if (! Moderator::aiEnabled()) {
            throw new HttpError(503, 'AI kontrolü henüz yapılandırılmadı. Manuel inceleme gerekli.');
        }

        return Output::noStore(AiAnalysis::run($u, AdminSchemas::analyze()->parse(Body::json($r))));
    }
}
