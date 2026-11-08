<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Reviews;
use App\Domain\Content\Schemas;
use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\HttpError;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ReviewController
{
    use Guards;

    private const VERIFY = 'E-posta doğrulaması gerekli.';

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, POST, OPTIONS');
    }

    public function index(Request $r): Response
    {
        if (! Respond::allowed($r)) {
            return Respond::cors($r, Respond::forbiddenText());
        }
        try {
            try {
                $input = HttpInput::publicReview($r);
            } catch (Throwable) {
                throw new HttpError(400, 'Geçersiz istek.');
            }

            return Respond::cors($r, Respond::json(Reviews::publicPage($input['businessId'], $input['after'])));
        } catch (Throwable $e) {
            return Respond::cors($r, ApiExceptionRenderer::render($e));
        }
    }

    public function store(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::authorReview($r);
            $u = $this->verified($r, self::VERIFY);
            $v = Schemas::review()->parse(Body::json($r));
            Schemas::id()->parse($v['businessId']);

            return Respond::json(['id' => Reviews::create($u, $v), 'status' => 'pending'], 201);
        });
    }

    public function mine(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::authorReview($r);
            $u = $this->verified($r, self::VERIFY);
            $businessId = Schemas::id()->parse(\App\Support\Query::from($r)->get('businessId'));

            return Respond::json(['review' => Reviews::own($u, $businessId)]);
        });
    }

    public function changeMine(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::authorReview($r);
            $u = $this->verified($r, self::VERIFY);
            $input = Schemas::authorReviewAction()->parse(Body::json($r));

            return Respond::json(['ok' => true, 'status' => Reviews::change($u, $input)]);
        });
    }

    public function mineOptions(Request $r): Response
    {
        return Respond::options($r, 'GET, PATCH, OPTIONS');
    }
}
