<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\CatalogueAdmin;
use App\Domain\Admin\Moderator;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CatalogueAdminController
{
    public function index(Request $r): JsonResponse
    {
        Moderator::admin($r);
        $q = Query::from($r);
        $after = $q->get('after');

        return Output::noStore(CatalogueAdmin::page($q->get('published') === 'true', ($after !== null && $after !== '') ? Schemas::id()->parse($after) : null));
    }

    public function update(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);
        CatalogueAdmin::publication($u, AdminSchemas::publicationAction()->parse(Body::json($r)));

        return Output::noStore(['ok' => true]);
    }

    public function draft(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);

        return Output::noStore(CatalogueAdmin::draft($u, Schemas::id()->parse(Query::from($r)->get('businessId'))));
    }

    public function saveDraft(Request $r): JsonResponse
    {
        $u = Moderator::admin($r);
        CatalogueAdmin::saveDraft($u, AdminSchemas::catalogueDraft()->parse(Body::json($r)));

        return Output::noStore(['ok' => true]);
    }

    public function history(Request $r): JsonResponse
    {
        Moderator::admin($r);
        $q = Query::from($r);
        $fields = [];
        foreach ($q->keys() as $key) {
            if ($q->count($key) !== 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            $fields[$key] = $q->get($key);
        }
        $input = AdminSchemas::catalogueHistoryQuery()->parse((object) $fields);

        return Output::noStore(CatalogueAdmin::history($input['businessId'], $input['after'] ?? null));
    }
}
