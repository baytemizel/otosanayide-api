<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\ActorResolver;
use App\Domain\Content\ReviewCases;
use App\Domain\Content\Schemas;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\Query;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReviewCaseController
{
    use Guards;

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, POST, OPTIONS');
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::cases($r);
            $u = ActorResolver::resolve($r);
            RateLimit::consume($u->uid);
            $after = Query::from($r)->get('after');
            $cursor = ($after !== null && $after !== '') ? Schemas::id()->parse($after) : null;

            return Respond::json(ReviewCases::page($u->uid, $cursor));
        });
    }

    public function store(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::cases($r);
            $u = $this->verified($r, 'E-posta doğrulaması gerekli.');
            ReviewCases::file($u, Schemas::caseInput()->parse(Body::json($r)));

            return Respond::json(['ok' => true]);
        });
    }
}
