<?php

namespace App\Http\Controllers\Api\Payments;

use App\Domain\Auth\Actor;
use App\Domain\Auth\ActorResolver;
use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Query;
use App\Support\RateLimit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** F7 handler'larinin ortak adimlari; sira her handler'da kaynakla ayni kalir. */
final class Gate
{
    public const FORBIDDEN = 'Bu işlem için yetkiniz yok.';

    /** financeAllowed(u): email_verified && admin && finance; sonra limit(uid). */
    public static function finance(Request $r): Actor
    {
        $u = ActorResolver::resolve($r);
        if (! self::financeAllowed($u)) {
            throw new HttpError(403, self::FORBIDDEN);
        }
        RateLimit::consume($u->uid);

        return $u;
    }

    /** core/payment-cases.ts financeAllowed */
    public static function financeAllowed(Actor $u): bool
    {
        return $u->emailVerified && $u->admin && $u->brandFinance;
    }

    public static function verified(Request $r, string $message = 'E-posta doğrulaması gerekli.'): Actor
    {
        $u = ActorResolver::resolve($r);
        if (! $u->emailVerified) {
            throw new HttpError(403, $message);
        }
        RateLimit::consume($u->uid);

        return $u;
    }

    /** [...p.keys()].some(k=>p.getAll(k).length!==1) -> 400; Object.fromEntries(p). */
    public static function params(Request $r): stdClass
    {
        $p = Query::from($r);
        $out = new stdClass;
        foreach ($p->keys() as $k) {
            if ($p->count($k) !== 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            $out->{$k} = $p->get($k);
        }

        return $out;
    }

    public static function body(Request $r): mixed
    {
        return Body::json($r);
    }

    /** Kaynakta origin === sunucu origin'i (Origin varsa). */
    public static function sameOriginIfPresent(Request $r): void
    {
        $origin = $r->headers->get('Origin');
        if ($origin !== null && $origin !== config('otosanayide.public_web_origin')) {
            throw new HttpError(403, self::FORBIDDEN);
        }
    }

    /** @param array<string,string> $headers */
    public static function json(mixed $value, array $headers = Api::PRIVATE, int $status = 200): JsonResponse
    {
        return Api::json($value, $status, $headers);
    }

    /**
     * try{...}catch(e){const r=await failure(e); r.headers.set(...)}
     *
     * @param  Closure():Response  $run
     * @param  array<string,string>  $errorHeaders
     */
    public static function guard(Closure $run, array $errorHeaders = []): Response
    {
        try {
            return $run();
        } catch (Throwable $e) {
            $res = ApiExceptionRenderer::render($e);
            foreach ($errorHeaders as $k => $v) {
                $res->headers->set($k, $v);
            }

            return $res;
        }
    }
}
