<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\User\Privacy;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Js;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

final class PrivacyController
{
    use Guards;

    private function run(Request $request, bool $write): JsonResponse
    {
        try {
            $this->noQuery($request);
            $u = $this->verified($request);
            if (! config('otosanayide.flags.data_requests')) {
                throw new HttpError(503, 'Veri talepleri henüz kullanıma açılmadı.');
            }
            if ($write) {
                if (! Privacy::recentLogin($u->authTime, Js::nowMs())) {
                    throw new HttpError(428, 'Güvenlik için çıkış yapıp tekrar giriş yap. Sonra talebini yeniden kontrol et.');
                }
                $this->jsonPrefix($request);
                try {
                    $raw = Body::bytes($request, 1024);
                } catch (HttpError) {
                    throw new HttpError(413, 'İstek çok büyük.');
                }
                try {
                    $input = Privacy::ownerAction()->parse(Body::decode($raw));
                } catch (Throwable) {
                    throw new HttpError(400, 'Geçersiz istek.');
                }
                $result = Privacy::changeOwn($u->uid, $input, Js::nowMs());
            } else {
                $result = Privacy::readOwn($u->uid);
            }

            return Api::json(['request' => $result, 'execution' => 'not_enabled']);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'privacy_')) {
                throw new HttpError(409, 'Talep durumu değişti. Yeniden kontrol et.');
            }
            throw $e;
        }
    }

    public function show(Request $request): JsonResponse
    {
        return $this->run($request, false);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run($request, true);
    }

    public function options(Request $request): Response
    {
        return $this->preflight($request, 'GET, POST, OPTIONS');
    }
}
