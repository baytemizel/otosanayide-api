<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Auth\Actor;
use App\Domain\Auth\ActorResolver;
use App\Domain\User\Privacy;
use App\Domain\User\PrivacyExports;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Js;
use App\Support\Query;
use App\Support\RateLimit;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class AdminPrivacyController
{
    private function admin(Request $request): Actor
    {
        $u = ActorResolver::resolve($request);
        if (! $u->admin || ! $u->emailVerified) {
            throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
        }
        RateLimit::consume($u->uid);
        if (! config('otosanayide.flags.data_requests')) {
            throw new HttpError(503, 'Veri talepleri henüz kullanıma açılmadı.');
        }

        return $u;
    }

    /** @param callable():JsonResponse $run */
    private function guard(callable $run): JsonResponse
    {
        try {
            return $run();
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'privacy_')) {
                throw new HttpError(409, 'Talep durumu değişti. Yeniden kontrol et.');
            }
            throw $e;
        }
    }

    public function index(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->admin($request);
            $q = Query::from($request);
            if ($q->has('kind')) {
                return $this->exportQueue($q); // web-10: yalniz ?kind=export ile, v1 yaniti degismez.
            }
            foreach ($q->keys() as $key) {
                if (! in_array($key, ['status', 'after'], true)) {
                    throw new HttpError(400, 'Geçersiz istek.');
                }
            }
            if ($q->count('status') > 1 || $q->count('after') > 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            $status = Z::enum(['submitted', 'reviewing', 'needs_confirmation'])->parse($q->get('status') ?? 'submitted');
            $cursor = null;
            $raw = $q->get('after');
            if ($raw !== null && $raw !== '') {
                try {
                    if (Js::length($raw) > 600) {
                        throw new RuntimeException('long');
                    }
                    $cursor = Z::strictObject(['createdAtMs' => Z::number()->int()->min(0)->max(8640000000000000), 'ownerUid' => Privacy::uid(), 'requestId' => Z::string()->uuid()])->parse(json_decode($raw, false, 16, JSON_THROW_ON_ERROR));
                } catch (Throwable) {
                    throw new HttpError(400, 'Geçersiz istek.');
                }
            }

            return Api::json(Privacy::queue($status, $cursor));
        });
    }

    private function exportQueue(Query $q): JsonResponse
    {
        foreach ($q->keys() as $key) {
            if (! in_array($key, ['kind', 'status', 'after'], true) || $q->count($key) > 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
        }
        $after = $q->get('after');
        if ($q->get('kind') !== 'export' || ($after !== null && preg_match('/^[0-9a-f-]{36}$/D', $after) !== 1)) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $status = Z::enum(['submitted', 'in_progress'])->parse($q->get('status') ?? 'submitted');

        return Api::json(PrivacyExports::queue($status, $after));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $u = $this->admin($request);
            if ($request->server->get('QUERY_STRING', '') !== '') {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            if (! Privacy::recentLogin($u->authTime, Js::nowMs())) {
                throw new HttpError(428, 'Güvenlik için çıkış yapıp tekrar giriş yap. Sonra talebini yeniden kontrol et.');
            }
            $raw = Body::json($request);
            if ($raw instanceof \stdClass && property_exists($raw, 'kind')) {
                return Api::json(['request' => PrivacyExports::decide($u->uid, PrivacyExports::adminSchema()->parse($raw)), 'preparation' => 'manual']);
            }
            $input = Privacy::adminAction()->parse($raw);

            return Api::json(['request' => Privacy::changeAdmin($u->uid, $input, Js::nowMs()), 'execution' => 'not_enabled']);
        });
    }
}
