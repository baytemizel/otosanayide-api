<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Onay kaydi kaynagi: web, portal veya native platform. Belirlenemezse null. */
final class ClientSource
{
    public static function of(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if ($origin !== null) {
            if (Origin::isPortal($origin)) {
                return 'portal';
            }

            return Origin::allowed($origin, (string) config('otosanayide.public_web_origin')) ? 'web' : null;
        }
        $platform = $request->headers->get('X-Client-Platform');

        return in_array($platform, ['ios', 'android'], true) ? $platform : null;
    }
}
