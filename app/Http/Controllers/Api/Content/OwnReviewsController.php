<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Reviews;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** web-17: GET /api/reviews/mine/all. */
final class OwnReviewsController
{
    use Guards;

    public function index(Request $r): JsonResponse
    {
        $p = Query::from($r);
        $this->assertKeys($p, ['after']);
        $cursor = null;
        $raw = $p->get('after');
        if ($raw !== null) {
            $c = strlen($raw) <= 400 && preg_match('/^[A-Za-z0-9_-]+$/D', $raw) === 1 ? json_decode((string) base64_decode(strtr($raw, '-_', '+/'), true), true) : null;
            if (! is_array($c) || ! is_string($c['t'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $c['t']) !== 1 || ! is_string($c['i'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $c['i']) !== 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            $cursor = $c;
        }
        $u = $this->verified($r, 'E-posta doğrulaması gerekli.');

        return Api::json(Reviews::ownAll($u, $cursor));
    }
}
