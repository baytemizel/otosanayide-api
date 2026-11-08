<?php

namespace App\Http\Controllers\Api\Catalogue;

use App\Domain\Catalogue\SearchFilter;
use App\Domain\Catalogue\SearchService;
use App\Http\Api;
use App\Support\I18n;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Throwable;

final class SearchController
{
    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Cache-Control' => 'no-store', 'Vary' => 'Origin'];
    }

    private function answer(Request $request, mixed $value, int $status = 200): JsonResponse
    {
        $response = Api::json($value, $status, $this->headers());
        if ($request->attributes->get('origin_allowed') === true && $request->headers->has('Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', (string) $request->headers->get('Origin'));
        }

        return $response;
    }

    public function options(Request $request): Response
    {
        if ($request->attributes->get('origin_allowed') !== true) {
            return Api::empty(403, $this->headers());
        }
        $response = Api::empty(204, $this->headers() + ['Access-Control-Allow-Methods' => 'GET, OPTIONS', 'Access-Control-Allow-Headers' => 'Accept, Accept-Language', 'Access-Control-Max-Age' => '600']);
        if ($request->headers->has('Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', (string) $request->headers->get('Origin'));
        }

        return $response;
    }

    public function index(Request $request, SearchService $service): JsonResponse
    {
        if ($request->attributes->get('origin_allowed') !== true) {
            return $this->answer($request, ['error' => 'Forbidden'], 403);
        }
        try {
            $filter = SearchFilter::parse(Query::from($request));
        } catch (InvalidArgumentException) {
            return $this->answer($request, ['error' => I18n::t('Arama filtrelerini kontrol et.')], 400);
        }
        try {
            // Reklam yerlesimi F7 (AD_SERVING_ENABLED kapali): kaynaktaki bos dizi.
            return $this->answer($request, ['items' => $service->search($filter), 'districts' => $service->districts($filter->city), 'sponsored' => [], 'mode' => 'live']);
        } catch (Throwable) {
            return $this->answer($request, ['error' => I18n::t('Arama altyapısı henüz yayına açılmadı.')], 503);
        }
    }
}
