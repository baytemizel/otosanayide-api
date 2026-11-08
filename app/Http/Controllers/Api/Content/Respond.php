<?php

namespace App\Http\Controllers\Api\Content;

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\HttpError;
use App\Http\Api;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Throwable;

/** Kaynaktaki cors()/allowed()/failure() kaliplari. Kapsam disi yollarda cagrilmaz. */
final class Respond
{
    public const JSON_HEADERS = 'Authorization, Content-Type, Accept, Accept-Language';

    public const READ_HEADERS = 'Authorization, Accept, Accept-Language';

    public static function allowed(Request $r): bool
    {
        return $r->attributes->get('origin_allowed') === true;
    }

    /** cors(): Cache-Control her zaman private, no-store olur. */
    public static function cors(Request $r, BaseResponse $res): BaseResponse
    {
        Api::exact($res, ['Cache-Control' => 'private, no-store', 'Vary' => 'Origin']);
        if (self::allowed($r) && $r->headers->has('Origin')) {
            $res->headers->set('Access-Control-Allow-Origin', (string) $r->headers->get('Origin'));
        }

        return $res;
    }

    /** OPTIONS: izin yoksa 403 + basliksiz, varsa 204 + preflight basliklari. */
    public static function options(Request $r, string $methods, string $headers = self::JSON_HEADERS): BaseResponse
    {
        if (! self::allowed($r)) {
            return self::cors($r, Api::empty(403, []));
        }

        return self::cors($r, Api::empty(204, ['Access-Control-Allow-Methods' => $methods, 'Access-Control-Allow-Headers' => $headers, 'Access-Control-Max-Age' => '600']));
    }

    /**
     * try{if(!allowed)throw 403;...return cors(run)}catch{return cors(failure)}
     *
     * @param  Closure():BaseResponse  $run
     */
    public static function guarded(Request $r, Closure $run, string $forbidden = 'Bu işlem için yetkiniz yok.'): BaseResponse
    {
        try {
            if (! self::allowed($r)) {
                throw new HttpError(403, $forbidden);
            }

            return self::cors($r, $run());
        } catch (Throwable $e) {
            return self::cors($r, ApiExceptionRenderer::render($e));
        }
    }

    public static function json(mixed $value, int $status = 200): JsonResponse
    {
        return Api::json($value, $status, []);
    }

    public static function forbiddenText(): JsonResponse
    {
        return Api::json(['error' => 'Forbidden'], 403, []);
    }

    /** Ozel gorsel yaniti; baslik kumesi kaynaga gore. */
    public static function image(string $bytes, string $filename, array $extra = []): BaseResponse
    {
        return Api::exact(new Response($bytes, 200), ['Content-Type' => 'image/webp', 'Content-Disposition' => 'inline; filename="'.$filename.'"', 'X-Content-Type-Options' => 'nosniff'] + $extra);
    }

    /** Next.js otomatik OPTIONS: kaynakta handler olmayan yollarda Cache-Control yok. */
    public static function nextAutoOptions(string $allow): BaseResponse
    {
        return self::withoutCacheControl(Api::empty(204, ['Allow' => $allow]));
    }

    /**
     * Kaynakta Cache-Control verilmeyen yanitlar (/api/claims POST/PATCH).
     *
     * @template T of BaseResponse
     *
     * @param  T  $response
     * @return T
     */
    public static function withoutCacheControl($response)
    {
        $response->headers->remove('Cache-Control');

        return $response;
    }
}
