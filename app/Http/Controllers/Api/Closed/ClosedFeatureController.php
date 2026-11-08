<?php

namespace App\Http\Controllers\Api\Closed;

use App\Domain\Auth\ActorResolver;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Query;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F7: sonraya birakilan uclar (brand*, campaigns, ad-events, payments). Veritabani ve odeme adaptoru YOK.
 * Kaynaktaki kapali bayrak yaniti, kaynagin yetki sirasi korunarak verilir. Bayrak acilsa bile uc kapali kalir.
 * Kaynakta bayraksiz okuma/yazma uclari icin ortak 503 doner (docs/ROUTE_PARITY.md: bekliyor).
 * Govde semalari burada dogrulanmaz: gecersiz govde de 503 alir, 400 almaz.
 */
final class ClosedFeatureController
{
    private const GENERIC = 'Bu özellik henüz etkin değil.';

    private const FINANCE = 'Bu işlem için yetkiniz yok.';

    /** @var array<string,array{gate:string,error?:string,origin?:string}> */
    public const SPECS = [
        'POST ad-events' => ['gate' => 'no-content'],
        'POST brand-events' => ['gate' => 'no-content-private'],
        'GET brand-banners' => ['gate' => 'banners'],
        'GET brand-banners/image' => ['gate' => 'banner-image'],
        'POST payments/webhook' => ['gate' => 'webhook'],
        'POST payments/checkout' => ['gate' => 'checkout', 'origin' => 'mobile', 'error' => 'Kartla ödeme henüz etkin değil. Kart bilgisi istenmez ve bu ekrandan ücret alınmaz.'],
        'GET campaigns' => ['gate' => 'verified', 'origin' => 'mobile'],
        'PATCH campaigns' => ['gate' => 'verified', 'origin' => 'mobile'],
        'GET campaigns/metrics' => ['gate' => 'verified-plain'],
        'GET campaigns/reservation' => ['gate' => 'verified'],
        'POST campaigns/reservation' => ['gate' => 'verified', 'error' => 'Reklam rezervasyonu henüz etkin değil. Ücret alınmadı.'],
        'GET campaigns/finance' => ['gate' => 'verified', 'origin' => 'mobile'],
        'POST campaigns/finance' => ['gate' => 'verified', 'origin' => 'mobile', 'error' => 'Reklam rezervasyonu henüz etkin değil. Ücret alınmadı.'],
        'GET campaigns/owner-metrics' => ['gate' => 'verified', 'origin' => 'mobile'],
        'GET admin/ad-tariffs' => ['gate' => 'finance'],
        'PATCH admin/ad-tariffs' => ['gate' => 'finance'],
        'GET admin/brand-acceptance' => ['gate' => 'finance'],
        'POST admin/brand-acceptance' => ['gate' => 'finance', 'error' => 'Marka reklamı kabul işlemleri henüz açılmadı.'],
        'GET admin/brand-campaigns' => ['gate' => 'finance'],
        'PATCH admin/brand-campaigns' => ['gate' => 'finance'],
        'GET admin/brand-cancellation-history' => ['gate' => 'finance'],
        'GET admin/brand-cancellation-queue' => ['gate' => 'finance'],
        'GET admin/brand-cancellations' => ['gate' => 'finance'],
        'POST admin/brand-cancellations' => ['gate' => 'finance-origin', 'error' => 'Yeni iptal işlemleri kapalı. Geçmiş kayıtlar okunabilir.'],
        'PATCH admin/brand-cancellations' => ['gate' => 'finance-origin', 'error' => 'Yeni iptal işlemleri kapalı. Geçmiş kayıtlar okunabilir.'],
        'PATCH admin/brand-capacities' => ['gate' => 'finance'],
        'GET admin/brand-media' => ['gate' => 'finance'],
        'POST admin/brand-media' => ['gate' => 'finance'],
        'GET admin/brand-metrics' => ['gate' => 'finance'],
        'GET admin/brand-orders' => ['gate' => 'finance'],
        'POST admin/brand-orders' => ['gate' => 'finance', 'error' => 'Marka reklamı ödeme hazırlığı henüz açılmadı.'],
        'GET admin/brand-payment-review' => ['gate' => 'finance'],
        'PATCH admin/brand-payment-review' => ['gate' => 'finance'],
        'GET admin/brand-preview' => ['gate' => 'finance'],
        'GET admin/brand-preview/image' => ['gate' => 'finance', 'error' => 'Yayın uygunluğu önizlemesi kapalı.'],
        'POST admin/brand-quote' => ['gate' => 'finance'],
        'GET admin/brand-refund-review' => ['gate' => 'finance'],
        'PATCH admin/brand-refund-review' => ['gate' => 'finance-origin', 'error' => 'Yeni uzlaştırma kararları kapalı. Geçmiş okunabilir.'],
        'GET admin/brand-reservations' => ['gate' => 'finance'],
        'POST admin/brand-reservations' => ['gate' => 'finance', 'error' => 'Marka rezervasyonları henüz açılmadı.'],
        'DELETE admin/brand-reservations' => ['gate' => 'finance'],
        'GET admin/brand-tariffs' => ['gate' => 'finance'],
        'PATCH admin/brand-tariffs' => ['gate' => 'finance'],
        'GET admin/payment-cases' => ['gate' => 'finance'],
        'PATCH admin/payment-cases' => ['gate' => 'finance'],
    ];

    public const OPTIONS = [
        'payments/checkout' => 'POST, OPTIONS',
        'campaigns' => 'GET, PATCH, OPTIONS',
        'campaigns/finance' => 'GET, POST, OPTIONS',
        'campaigns/owner-metrics' => 'GET, OPTIONS',
    ];

    public function __invoke(Request $request): Response
    {
        $path = substr($request->path(), 4);
        $spec = self::SPECS[$request->method().' '.$path];
        $gate = $spec['gate'];
        switch ($gate) {
            case 'no-content':
                return Api::empty(204, []);
            case 'no-content-private':
                return Api::empty(204);
            case 'banners':
                $this->uniqueKeys($request);

                return Api::json(['items' => []], 200, ['Cache-Control' => 'private, no-store', 'Vary' => 'X-Firebase-AppCheck']);
            case 'banner-image':
                return Api::empty(404, ['Cache-Control' => 'private, no-store', 'Vary' => 'X-Firebase-AppCheck', 'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Disposition' => 'inline']);
            case 'webhook':
                return Api::json(['received' => false], 503);
            case 'checkout':
                if ($request->server->get('QUERY_STRING', '') !== '') {
                    throw new HttpError(403, self::FINANCE);
                }
                $this->verified($request, 'E-posta doğrulaması gerekli.');
                if (preg_match('/^application\/json(?:\s*;|$)/iD', (string) $request->headers->get('Content-Type')) !== 1) {
                    throw new HttpError(415, 'Geçersiz istek.');
                }
                break;
            case 'verified':
                $this->verified($request, 'E-posta doğrulaması gerekli.');
                break;
            case 'verified-plain':
                $this->verified($request, self::FINANCE);
                break;
            case 'finance':
            case 'finance-origin':
                $u = ActorResolver::resolve($request);
                if (! $u->emailVerified || ! $u->admin || ! $u->brandFinance) {
                    throw new HttpError(403, self::FINANCE);
                }
                RateLimit::consume($u->uid);
                if ($gate === 'finance-origin') {
                    $origin = $request->headers->get('Origin');
                    if ($origin !== null && $origin !== config('otosanayide.public_web_origin')) {
                        throw new HttpError(403, self::FINANCE);
                    }
                }
                break;
        }

        throw new HttpError(503, $spec['error'] ?? self::GENERIC);
    }

    public function options(Request $request): Response
    {
        $allowed = $request->attributes->get('origin_allowed') === true;
        $path = substr($request->path(), 4);

        return Api::empty($allowed ? 204 : 403, ['Cache-Control' => 'private, no-store', 'Vary' => 'Origin'] + ($allowed ? ['Access-Control-Allow-Methods' => self::OPTIONS[$path], 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, Accept-Language', 'Access-Control-Max-Age' => '600'] : []));
    }

    private function verified(Request $request, string $message): void
    {
        $u = ActorResolver::resolve($request);
        if (! $u->emailVerified) {
            throw new HttpError(403, $message);
        }
        RateLimit::consume($u->uid);
    }

    private function uniqueKeys(Request $request): void
    {
        $p = Query::from($request);
        foreach ($p->keys() as $key) {
            if ($p->count($key) !== 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
        }
    }
}
