<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Base;
use Illuminate\Http\Request;

/**
 * K179 bulgu 3: API Cloudflare arkasinda. TRUSTED_PROXIES bossa hicbir proxy'ye guvenilmez (onceki davranis:
 * istemci IP'si REMOTE_ADDR). Doluysa yalniz o adreslerden gelen X-Forwarded-For/Proto/Port okunur.
 * X-Forwarded-Host bilerek disarida: origin ve baglantilar Host'tan degil yapilandirmadan gelir.
 */
final class TrustProxies extends Base
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    /** @return array<int,string>|string|null */
    protected function proxies()
    {
        $value = config('otosanayide.trusted_proxies');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        return $value === '*' ? '*' : array_values(array_filter(array_map(trim(...), explode(',', $value)), fn (string $ip) => $ip !== ''));
    }
}
