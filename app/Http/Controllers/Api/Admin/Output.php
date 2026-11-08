<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Api;
use App\Http\Controllers\Api\Content\Respond;
use Illuminate\Http\JsonResponse;

/** Kaynakta GET'ler 'no-store', cogu PATCH basliksiz doner; hata yanitlari renderer'dandir. */
final class Output
{
    public static function noStore(mixed $value, int $status = 200): JsonResponse
    {
        return Api::json($value, $status, ['Cache-Control' => 'no-store']);
    }

    public static function bare(mixed $value, int $status = 200): JsonResponse
    {
        return Respond::withoutCacheControl(Api::json($value, $status, []));
    }
}
