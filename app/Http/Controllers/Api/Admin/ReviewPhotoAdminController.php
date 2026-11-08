<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\Moderator;
use App\Domain\Admin\PhotoModeration;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReviewPhotoAdminController
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

        return Output::noStore(PhotoModeration::reviewPage($status, ($after !== null && $after !== '') ? Schemas::id()->parse($after) : null));
    }

    public function update(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);
        PhotoModeration::decideReview($u, AdminSchemas::reviewPhotoDecision()->parse(Body::json($r)));

        return Output::bare(['ok' => true]);
    }
}
