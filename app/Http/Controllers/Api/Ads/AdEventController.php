<?php

namespace App\Http\Controllers\Api\Ads;

use App\Domain\Ads\AdMetrics;
use App\Domain\Payments\F7;
use App\Http\Api;
use App\Support\Body;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** POST /api/ad-events: kapali bayrakta 204; Origin sunucu origin'i olmali; her hata 400 (govdesiz). */
final class AdEventController
{
    public function __invoke(Request $r): Response
    {
        if (! F7::flag('ad_metrics') || ! F7::flag('ad_serving')) {
            return Api::empty(204, []);
        }
        try {
            if ($r->headers->get('Origin') !== config('otosanayide.public_web_origin')) {
                return Api::empty(403, []);
            }
            AdMetrics::record(Body::json($r), AdMetrics::secret() ?? '');

            return Api::empty(204, []);
        } catch (Throwable) {
            return Api::empty(400, []);
        }
    }
}
