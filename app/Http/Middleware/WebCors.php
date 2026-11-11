<?php

namespace App\Http\Middleware;

use App\Http\Api;
use App\Support\Origin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Web uygulamasi API'yi ayri alan adindan cagirir (K180). Yalniz PUBLIC_WEB_ORIGIN ile birebir
 * eslesen istekler CORS yaniti alir; diger origin'ler icin OriginGuard ve route davranisi aynen kalir.
 * Kimlik bilgisi izni yalniz yenileme cerezinin kullanildigi /api/auth altindadir.
 * Sozlesme Eki v1: PORTAL_WEB_ORIGIN de ayni kurallarla hizmet alir; /api/admin altinda portal CORS almaz.
 */
final class WebCors
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const REQUEST_HEADERS = 'Authorization, Content-Type, Accept, Accept-Language, X-Firebase-AppCheck';

    private const EXPOSED_HEADERS = 'X-Garage-Revision';

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || ! $request->is('api/*') || ! $this->served($request, $origin)) {
            return $next($request);
        }
        $method = $request->headers->get('Access-Control-Request-Method');
        if ($request->isMethod('OPTIONS') && is_string($method) && $this->routable($request, $method)) {
            return $this->decorate($request, Api::empty(204, Api::PRIVATE + [
                'Access-Control-Allow-Methods' => $method,
                'Access-Control-Allow-Headers' => self::REQUEST_HEADERS,
                'Access-Control-Max-Age' => '600',
            ]), $origin);
        }

        return $this->decorate($request, $next($request), $origin);
    }

    private function served(Request $request, string $origin): bool
    {
        if ($origin === config('otosanayide.public_web_origin')) {
            return true;
        }

        return Origin::isPortal($origin) && ! $request->is('api/admin', 'api/admin/*');
    }

    private function routable(Request $request, string $method): bool
    {
        if (! in_array($method, self::METHODS, true)) {
            return false;
        }
        try {
            Route::getRoutes()->match(Request::create($request->getPathInfo(), $method));

            return true;
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }
    }

    private function decorate(Request $request, Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);
        if ($request->is('api/auth/*')) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
        if (! in_array('Origin', $response->getVary(), true)) {
            $response->setVary('Origin', false);
        }

        return $response;
    }
}
