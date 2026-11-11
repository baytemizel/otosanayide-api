<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminOverview;
use App\Domain\Admin\Moderator;
use App\Domain\Content\BusinessReports;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** web-16 (madde 1, 4) ve web-04 yonetici kuyrugu. Portal origin'i /api/admin altinda CORS ve origin izni almaz. */
final class OperationsAdminController
{
    private function noQuery(Request $r): void
    {
        if ($r->server->get('QUERY_STRING', '') !== '') {
            throw new HttpError(400, 'Geçersiz istek.');
        }
    }

    public function overview(Request $r): JsonResponse
    {
        $this->noQuery($r);
        Moderator::admin($r);

        return Output::noStore(AdminOverview::queues());
    }

    public function flags(Request $r): JsonResponse
    {
        $this->noQuery($r);
        Moderator::admin($r);

        return Output::noStore(AdminOverview::flags());
    }

    public function reports(Request $r): JsonResponse
    {
        $q = Query::from($r);
        foreach ($q->keys() as $k) {
            if (! in_array($k, ['status', 'after'], true) || $q->count($k) > 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
        }
        $status = $q->get('status') ?? 'submitted';
        $after = $q->get('after');
        if (! in_array($status, ['submitted', 'resolved', 'dismissed'], true) || ($after !== null && preg_match('/^[0-9a-f-]{36}$/D', $after) !== 1)) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        Moderator::admin($r);

        return Output::noStore(BusinessReports::queue($status, $after));
    }

    public function decideReport(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $u = Moderator::admin($r);
        BusinessReports::decide($u->uid, BusinessReports::decisionSchema()->parse(Body::json($r)));

        return Output::bare(['ok' => true]);
    }
}
