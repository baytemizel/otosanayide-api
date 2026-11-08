<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\ReviewPhotos;
use App\Domain\Content\Schemas;
use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\HttpError;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ReviewPhotoController
{
    use Guards;

    private const VERIFY = 'E-posta doğrulaması gerekli.';

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, POST, PATCH, OPTIONS');
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::ownPhoto($r);
            $u = $this->verified($r, self::VERIFY);
            $q = Query::from($r);
            $reviewId = Schemas::id()->parse($q->get('reviewId'));

            return Respond::json(ReviewPhotos::list($u, $reviewId, $q->get('version')));
        });
    }

    public function store(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::ownPhoto($r);
            $u = $this->verified($r, self::VERIFY);
            $q = Query::from($r);
            $reviewId = Schemas::id()->parse($q->get('reviewId'));
            if ($q->get('rights') !== 'yes') {
                throw new HttpError(400, 'Fotoğraf paylaşım beyanını onayla.');
            }

            return Respond::json(ReviewPhotos::upload($u, $r, $reviewId, $q->get('version')), 201);
        });
    }

    public function update(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::ownPhoto($r);
            $u = $this->verified($r, self::VERIFY);
            ReviewPhotos::withdraw($u, Schemas::reviewPhotoWithdraw()->parse(Body::json($r)));

            return Respond::json(['ok' => true]);
        });
    }

    public function mediaOptions(Request $r): Response
    {
        return Respond::options($r, 'GET, OPTIONS', Respond::READ_HEADERS);
    }

    public function media(Request $r): Response
    {
        if (! Respond::allowed($r)) {
            return Respond::cors($r, Respond::forbiddenText());
        }
        try {
            try {
                $query = HttpInput::reviewPhotoQuery($r);
            } catch (Throwable) {
                throw new HttpError(400, 'Geçersiz istek.');
            }

            return Respond::cors($r, Respond::image(ReviewPhotos::media($r, $query), 'review-photo.webp'));
        } catch (Throwable $e) {
            return Respond::cors($r, ApiExceptionRenderer::render($e));
        }
    }
}
