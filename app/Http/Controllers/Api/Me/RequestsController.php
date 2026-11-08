<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\User\RequestRules;
use App\Domain\User\Requests;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Query;
use App\Support\ZodError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

final class RequestsController
{
    use Guards;

    /** requestHttpInput() */
    private function httpInput(Request $request): Query
    {
        $p = Query::from($request);
        try {
            $this->assertKeys($p, $request->isMethod('GET') ? ['businessId', 'after'] : []);
            if ($request->isMethod('GET')) {
                if ($p->has('businessId')) {
                    RequestRules::id()->parse($p->get('businessId'));
                }
                if ($p->get('after')) {
                    RequestRules::id()->parse($p->get('after'));
                }
            }
        } catch (ZodError) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        if (! $request->isMethod('GET')) {
            $this->jsonStrict($request);
        }

        return $p;
    }

    public function index(Request $request): JsonResponse
    {
        $p = $this->httpInput($request);
        $u = $this->verified($request);

        return Api::json(Requests::page($u->uid, $p->get('businessId'), $p->get('after')));
    }

    public function store(Request $request): JsonResponse
    {
        $this->httpInput($request);
        $u = $this->verified($request, 'E-posta doğrulaması gerekli.');

        return Api::json(Requests::create($u->uid, RequestRules::create()->parse(Body::json($request))), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $this->httpInput($request);
        $u = $this->verified($request, 'E-posta doğrulaması gerekli.');
        $raw = Body::json($request);
        $owned = $raw instanceof stdClass && property_exists($raw, 'businessId') ? RequestRules::ownerWrite()->parse($raw) : null;
        $input = $owned !== null ? $owned['value'] : RequestRules::action()->parse($raw);
        Requests::act($u->uid, $input, $owned['businessId'] ?? null);

        return Api::json(['ok' => true]);
    }

    public function options(Request $request): Response
    {
        return $this->preflight($request, 'GET, POST, PATCH, OPTIONS');
    }
}
