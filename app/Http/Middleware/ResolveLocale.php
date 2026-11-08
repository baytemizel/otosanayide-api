<?php

namespace App\Http\Middleware;

use App\Support\I18n;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class ResolveLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(I18n::resolve($request->cookies->get('otosanayide-locale'), $request->headers->get('Accept-Language')));

        return $next($request);
    }
}
