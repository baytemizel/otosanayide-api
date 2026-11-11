<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\User\RequestRules;
use App\Domain\User\RequestThread;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Query;
use App\Support\ZodError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** web-15 madde 2: GET/POST /api/requests/messages. */
final class RequestThreadController
{
    use Guards;

    public function index(Request $r): JsonResponse
    {
        $p = Query::from($r);
        $this->assertKeys($p, ['id', 'after']);
        try {
            RequestRules::id()->parse($p->get('id'));
        } catch (ZodError) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $after = $p->get('after');
        if ($after !== null && preg_match('/^[0-9a-f-]{36}$/D', $after) !== 1) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $u = $this->verified($r);

        return Api::json(RequestThread::page($u->uid, (string) $p->get('id'), $after));
    }

    public function store(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r, 'E-posta doğrulaması gerekli.');
        $out = RequestThread::post($u->uid, RequestThread::postSchema()->parse(Body::json($r)));

        return Api::json($out['message'], $out['created'] ? 201 : 200);
    }
}
