<?php

namespace App\Http\Controllers\Api;

use App\Http\Api;
use Illuminate\Http\JsonResponse;

/** Yalniz canlilik: veritabani, sir, kullanici veya saglayici bilgisi yok. */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return Api::json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
