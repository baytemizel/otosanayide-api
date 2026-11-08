<?php

namespace App\Http\Middleware;

use App\Exceptions\HttpError;
use App\Support\Origin;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kaynakta 25 route origin karsilastirir, dort bicimde:
 *  mobile  garageOriginAllowed(origin, server, MOBILE_WEB_ORIGIN) + CORS yansitma
 *  web     garageOriginAllowed(origin, server) (mobil origin yok)
 *  exact   origin === server, Origin basligi zorunlu
 *  present Origin varsa server olmali
 * Beklenen origin PUBLIC_WEB_ORIGIN'dir; Host basligi kullanilmaz.
 */
final class OriginGuard
{
    public function handle(Request $request, Closure $next, string $mode = 'mobile', string $cors = 'cors'): Response
    {
        $server = config('otosanayide.public_web_origin');
        if (! is_string($server) || $server === '') {
            throw new RuntimeException('PUBLIC_WEB_ORIGIN missing');
        }
        $mobile = config('otosanayide.mobile_web_origin');
        $origin = $request->headers->get('Origin');
        $allowed = match ($mode) {
            'mobile' => Origin::allowed($origin, $server, is_string($mobile) ? $mobile : null),
            'web' => Origin::allowed($origin, $server),
            'exact' => $origin === $server,
            'present' => $origin === null || $origin === $server,
            default => throw new RuntimeException('Unknown origin mode'),
        };
        $request->attributes->set('origin_allowed', $allowed);
        if ($cors === 'manual') {
            return $next($request); // Handler kaynak gibi kendi ret ve CORS yanitini kurar.
        }
        if ($request->isMethod('OPTIONS')) {
            $response = $next($request);
        } elseif (! $allowed) {
            throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
        } else {
            $response = $next($request);
        }
        if ($mode === 'mobile' && $cors === 'cors') {
            $response->headers->set('Vary', 'Origin');
            if ($allowed && $origin !== null) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
            }
        }

        return $response;
    }
}
