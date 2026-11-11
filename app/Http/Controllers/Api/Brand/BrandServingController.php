<?php

namespace App\Http\Controllers\Api\Brand;

use App\Domain\Ads\AdRules;
use App\Domain\Brand\BrandDelivery;
use App\Domain\Brand\BrandMedia;
use App\Domain\Brand\BrandServing;
use App\Domain\Payments\F7;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Http\Controllers\Api\Payments\Gate;
use App\Support\Body;
use App\Support\Z;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** /api/brand-banners, /brand-banners/image, /brand-events, /admin/brand-media, /admin/brand-preview(/image), /admin/brand-metrics */
final class BrandServingController
{
    private const PUBLIC = ['Cache-Control' => 'private, no-store', 'Vary' => 'X-Firebase-AppCheck'];

    private const IMAGE = ['Cache-Control' => 'private, no-store', 'Vary' => 'X-Firebase-AppCheck', 'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Disposition' => 'inline'];

    private const PREVIEW = ['Cache-Control' => 'private, no-store', 'Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cross-Origin-Resource-Policy' => 'same-origin'];

    private function bytes(string $bytes, array $headers): Response
    {
        return Api::exact(new HttpResponse($bytes, 200), $headers);
    }

    public function banners(Request $r): Response
    {
        return Gate::guard(function () use ($r): Response {
            $context = BrandDelivery::context()->parse(Gate::params($r));
            if (! BrandServing::servingEnabled()) {
                return Api::json(['items' => []], 200, self::PUBLIC);
            }
            $secret = BrandServing::publicAccess($r);

            return Api::json(['items' => BrandServing::serve($context, $secret, F7::nowMs(), BrandServing::metricSecret())], 200, self::PUBLIC);
        }, self::PUBLIC);
    }

    public function image(Request $r): Response
    {
        return Gate::guard(function () use ($r): Response {
            if (! BrandServing::servingEnabled()) {
                return Api::empty(404, self::IMAGE);
            }
            $token = Z::strictObject(['token' => Z::string()->min(1)->max(2048)->regex('/^[A-Za-z0-9_-]+$/')])->parse(Gate::params($r))['token'];
            $secret = BrandServing::publicAccess($r);
            try {
                $bytes = BrandServing::publicImage($token, $secret, BrandServing::loader());
            } catch (Throwable) {
                throw new HttpError(404, 'Reklam görseli şu anda kullanılamıyor.');
            }

            return $this->bytes($bytes, self::IMAGE + ['Content-Type' => 'image/webp']);
        }, self::IMAGE);
    }

    public function events(Request $r): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        if (! BrandServing::servingEnabled() || ! F7::flag('brand_metrics')) {
            return Api::empty(204, $headers);
        }
        try {
            $origin = $r->headers->get('Origin');
            if ($origin !== null && $origin !== '' && $origin !== config('otosanayide.public_web_origin')) {
                return Api::empty(403, $headers);
            }
            BrandServing::publicAccess($r);
            BrandServing::record(Body::json($r), (string) BrandServing::metricSecret());

            return Api::empty(204, $headers);
        } catch (Throwable) {
            return Api::empty(400, $headers);
        }
    }

    public function media(Request $r): Response
    {
        $u = Gate::finance($r);
        $campaignId = Z::strictObject(['campaignId' => F7::uuid()])->parse(Gate::params($r))['campaignId'];

        return $this->bytes(BrandMedia::view($u->uid, $campaignId), ['Cache-Control' => 'private, no-store', 'Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function upload(Request $r): Response
    {
        $u = Gate::finance($r);
        $q = Z::strictObject(['campaignId' => F7::uuid(), 'version' => Z::string()->regex('/^\d{1,8}$/'), 'rights' => Z::literal('yes')])->parse(Gate::params($r));
        if (! in_array((string) $r->headers->get('Content-Type'), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new HttpError(415, 'JPEG, PNG veya WebP gerekli.');
        }
        $item = BrandMedia::upload($u->uid, $q['campaignId'], (int) $q['version'], static fn (): string => Body::bytes($r, 2500000));

        return Api::json(['item' => $item], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $r): Response
    {
        Gate::finance($r);
        try {
            $context = BrandDelivery::input()->parse(Gate::params($r));
        } catch (Throwable) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $now = F7::nowMs();
        $result = F7::flag('brand_preview') ? BrandDelivery::evaluate((object) $context, true, $now) : ['eligible' => false, 'reason' => 'disabled', 'ad' => null];

        return Gate::json(['context' => $context] + $result + ['checkedAtMs' => $now]);
    }

    public function previewImage(Request $r): Response
    {
        return Gate::guard(function () use ($r): Response {
            Gate::finance($r);
            try {
                $input = BrandDelivery::imageInput()->parse(Gate::params($r));
            } catch (Throwable) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            if (! F7::flag('brand_preview')) {
                throw new HttpError(503, 'Yayın uygunluğu önizlemesi kapalı.');
            }
            $load = BrandServing::loader();
            try {
                $bytes = BrandServing::previewImage($input, $load);
            } catch (Throwable) {
                throw new HttpError(409, 'Banner görseli doğrulanamadı veya reklam koşulları değişti. Önizlemeyi yenile.');
            }

            return $this->bytes($bytes, self::PREVIEW);
        }, ['Cache-Control' => 'private, no-store']);
    }

    public function metrics(Request $r): Response
    {
        return Gate::guard(function () use ($r): Response {
            Gate::finance($r);
            $campaignId = AdRules::metricQuery()->parse(Gate::params($r))['campaignId'];
            try {
                $report = BrandServing::report($campaignId);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'missing') {
                    throw new HttpError(404, 'Kayıt bulunamadı.');
                }
                throw $e;
            }

            return Gate::json($report + ['enabled' => F7::flag('brand_metrics'), 'campaignId' => $campaignId]);
        }, ['Cache-Control' => 'private, no-store']);
    }
}
