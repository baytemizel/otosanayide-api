<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\ActorResolver;
use App\Domain\Content\ClaimEvidence;
use App\Domain\Content\Schemas;
use App\Http\Api;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\Query;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClaimEvidenceController
{
    use Guards;

    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    private const VERIFY = 'E-posta doğrulaması gerekli.';

    public function options(): Response
    {
        return Respond::nextAutoOptions('GET, HEAD, OPTIONS, PATCH, POST');
    }

    public function index(Request $r): Response
    {
        $u = ActorResolver::resolve($r);
        RateLimit::consume($u->uid);
        $q = Query::from($r);
        $claimId = Schemas::id()->parse($q->get('claimId'));
        $id = $q->get('id');
        if ($id === null || $id === '') {
            return Api::json(ClaimEvidence::list($u, $claimId), 200, self::PRIVATE_HEADERS);
        }

        return Respond::image(ClaimEvidence::download($u, $claimId, $id), 'belge.webp', ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $r): Response
    {
        $u = $this->verified($r, self::VERIFY);
        $q = Query::from($r);
        $claimId = Schemas::id()->parse($q->get('claimId'));
        $kind = Schemas::evidenceKind()->parse($q->get('kind'));

        return Api::json(ClaimEvidence::upload($u, $r, $claimId, $kind), 201, self::PRIVATE_HEADERS);
    }

    public function update(Request $r): Response
    {
        $u = $this->verified($r, self::VERIFY);
        ClaimEvidence::withdraw($u, Schemas::evidenceWithdraw()->parse(Body::json($r)));

        return Api::json(['ok' => true], 200, self::PRIVATE_HEADERS);
    }
}
