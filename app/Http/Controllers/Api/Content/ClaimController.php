<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\ActorResolver;
use App\Domain\Content\Claims;
use App\Domain\Content\Schemas;
use App\Http\Api;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\RateLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Kaynakta origin kontrolu ve OPTIONS handler'i yok; hatalar genel renderer'dan gecer. */
final class ClaimController
{
    use Guards;

    private const VERIFY = 'E-posta doğrulaması gerekli.';

    public function options(): Response
    {
        return Respond::nextAutoOptions('GET, HEAD, OPTIONS, PATCH, POST');
    }

    public function store(Request $r): JsonResponse
    {
        $u = $this->verified($r, self::VERIFY);
        $v = Schemas::claim()->parse(Body::json($r));

        return Respond::withoutCacheControl(Api::json(['id' => Claims::submit($u, $v), 'status' => 'submitted'], 201, []));
    }

    public function index(Request $r): JsonResponse
    {
        $u = ActorResolver::resolve($r);
        RateLimit::consume($u->uid);

        return Api::json(['claims' => Claims::own($u->uid), 'limit' => 50], 200, ['Cache-Control' => 'no-store']);
    }

    public function update(Request $r): JsonResponse
    {
        $u = $this->verified($r, self::VERIFY);
        Claims::reply($u, Schemas::claimReply()->parse(Body::json($r)));

        return Respond::withoutCacheControl(Api::json(['ok' => true, 'status' => 'submitted'], 200, []));
    }
}
