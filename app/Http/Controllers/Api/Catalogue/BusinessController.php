<?php

namespace App\Http\Controllers\Api\Catalogue;

use App\Domain\Catalogue\BusinessReader;
use App\Exceptions\HttpError;
use App\Http\Api;
use Illuminate\Http\JsonResponse;

/** Yeni uc: web servis/[id] sayfasi kaynakta sunucu kodunu dogrudan okuyordu (K170). Yanit searchBusiness DTO'sudur. */
final class BusinessController
{
    public function show(string $id): JsonResponse
    {
        $record = preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $id) === 1 ? (BusinessReader::records([$id])[$id] ?? null) : null;
        $business = $record === null ? null : BusinessReader::publicBusiness($id, $record);
        if ($business === null) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }

        return Api::json($business, 200, ['Cache-Control' => 'no-store']);
    }
}
