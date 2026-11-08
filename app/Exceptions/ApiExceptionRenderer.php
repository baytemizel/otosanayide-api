<?php

namespace App\Exceptions;

use App\Http\Api;
use App\Support\I18n;
use Illuminate\Http\JsonResponse;
use App\Support\ZodError;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Kaynaktaki `failure(e)`: disariya yalniz {"error":"..."} cikar. */
final class ApiExceptionRenderer
{
    private const GENERIC = 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.';

    private const HEADERS = ['Cache-Control' => 'private, no-store'];

    public static function render(Throwable $e): JsonResponse
    {
        if ($e instanceof ZodError) {
            return self::json($e->issue === 'custom' ? $e->getMessage() : 'Bilgileri kontrol edin.', 400);
        }
        if ($e instanceof WriteConflict) {
            return self::json(self::GENERIC, 409);
        }
        if ($e instanceof HttpError) {
            return self::json($e->getMessage(), $e->status);
        }
        if ($e instanceof PostTooLargeException) {
            return self::json('İstek çok büyük.', 413);
        }
        if ($e instanceof ThrottleRequestsException) {
            return self::json('Çok fazla işlem. Bir dakika sonra yeniden deneyin.', 429);
        }
        if ($e instanceof NotFoundHttpException) {
            return self::json('Bulunamadı.', 404);
        }
        if ($e instanceof MethodNotAllowedHttpException) {
            return self::json('Geçersiz istek.', 405)->withHeaders($e->getHeaders());
        }
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 503) {
            return self::json(self::GENERIC, 503);
        }

        // Kaynak gibi yalniz hata sinifi yazilir; mesaj ozel veri tasiyabilir.
        Log::error('Request failed', ['name' => $e::class]);

        return self::json(self::GENERIC, 500);
    }

    private static function json(string $message, int $status): JsonResponse
    {
        return Api::json(['error' => I18n::t($message)], $status, self::HEADERS);
    }
}
