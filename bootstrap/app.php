<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\OriginGuard;
use App\Http\Middleware\ResolveLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Yalniz gerekli global katmanlar: TrimStrings/ConvertEmptyStringsToNull ham gövdeyi degistirmesin.
        $middleware->use([
            \Illuminate\Http\Middleware\TrustProxies::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Http\Middleware\ValidatePostSize::class,
            ResolveLocale::class,
        ]);
        $middleware->group('api', []);
        $middleware->alias(['origin' => OriginGuard::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([App\Exceptions\HttpError::class, App\Support\ZodError::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e));
    })->create();
