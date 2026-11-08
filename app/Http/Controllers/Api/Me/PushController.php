<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Auth\Actor;
use App\Domain\User\PushDevices;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Env;
use RuntimeException;

final class PushController
{
    use Guards;

    private function access(Request $request): Actor
    {
        if ($request->attributes->get('origin_allowed') !== true || $request->server->get('QUERY_STRING', '') !== '') {
            throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
        }
        if (! config('otosanayide.flags.push_delivery')) {
            throw new HttpError(503, 'Bildirim gönderimi henüz etkin değil.');
        }

        return $this->verified($request);
    }

    public function index(Request $request): JsonResponse
    {
        return Api::json(PushDevices::list($this->access($request)->uid));
    }

    public function store(Request $request): JsonResponse
    {
        $u = $this->access($request);
        $this->jsonStrict($request);
        $ids = array_map(trim(...), explode(',', (string) Env::getRepository()->get('PUSH_PUBLIC_APP_IDS')));
        try {
            return Api::json(PushDevices::write($u->uid, Body::json($request), $ids));
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'push_')) {
                throw new HttpError(409, 'Bildirim ayarları değişti. Yenileyip tekrar deneyin.');
            }
            throw $e;
        }
    }

    public function options(Request $request): Response
    {
        return $this->preflight($request, 'GET, POST, OPTIONS', 'Authorization, Content-Type, Accept, Accept-Language');
    }
}
