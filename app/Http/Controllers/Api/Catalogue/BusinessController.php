<?php

namespace App\Http\Controllers\Api\Catalogue;

use App\Domain\Catalogue\BusinessReader;
use App\Domain\Catalogue\ReviewSummary;
use App\Domain\Catalogue\SearchService;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Yeni uc: web servis/[id] sayfasi kaynakta sunucu kodunu dogrudan okuyordu (K170). Yanit searchBusiness DTO'sudur.
 * `?v=2` (web-07/web-08): ek olarak `reviewSummary` ve varsa `openingHours`. v=2 yoksa yanit degismez.
 */
final class BusinessController
{
    public function show(Request $request, string $id): JsonResponse
    {
        $v = Query::from($request)->get('v');
        if ($v !== null && $v !== '2') {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $record = preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $id) === 1 ? (BusinessReader::records([$id])[$id] ?? null) : null;
        $business = $record === null ? null : BusinessReader::publicBusiness($id, $record);
        if ($business === null) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }
        if ($v === '2') {
            $s = ReviewSummary::compute($id);
            $business = SearchService::extend($business, $s['count'], $s['sum'], $record['openingHoursJson'] ?? null);
        }

        return Api::json($business, 200, ['Cache-Control' => 'no-store']);
    }
}
