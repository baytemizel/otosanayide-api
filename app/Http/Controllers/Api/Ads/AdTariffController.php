<?php

namespace App\Http\Controllers\Api\Ads;

use App\Domain\Ads\AdRules;
use App\Domain\Ads\AdTariffs;
use App\Http\Api;
use App\Http\Controllers\Api\Payments\Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** /api/admin/ad-tariffs (finans yetkisi) */
final class AdTariffController
{
    public function show(Request $r): Response
    {
        Gate::finance($r);
        $market = AdRules::market()->parse(Gate::params($r));

        return Api::json(AdTariffs::read($market), 200, ['Cache-Control' => 'no-store']);
    }

    public function update(Request $r): Response
    {
        $u = Gate::finance($r);
        $input = AdRules::tariffUpdate()->parse(Gate::body($r));

        return Api::json(AdTariffs::update($u->uid, $input), 200, ['Cache-Control' => 'no-store']);
    }
}
