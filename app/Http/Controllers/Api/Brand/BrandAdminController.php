<?php

namespace App\Http\Controllers\Api\Brand;

use App\Domain\Brand\BrandAdmin;
use App\Domain\Brand\BrandInventory;
use App\Domain\Brand\BrandMedia;
use App\Domain\Brand\BrandOrders;
use App\Domain\Brand\BrandRules;
use App\Domain\Payments\F7;
use App\Domain\Payments\PaymentGateway;
use App\Exceptions\HttpError;
use App\Http\Controllers\Api\Payments\Gate;
use App\Support\Z;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** /api/admin/brand-tariffs, brand-capacities, brand-quote, brand-campaigns, brand-reservations, brand-orders */
final class BrandAdminController
{
    public function tariff(Request $r): Response
    {
        Gate::finance($r);

        return Gate::json(BrandAdmin::tariff(BrandRules::market()->parse(Gate::params($r))));
    }

    public function saveTariff(Request $r): Response
    {
        $u = Gate::finance($r);

        return Gate::json(BrandAdmin::saveTariff($u->uid, BrandRules::tariffUpdate()->parse(Gate::body($r))));
    }

    public function saveCapacity(Request $r): Response
    {
        $u = Gate::finance($r);

        return Gate::json(BrandAdmin::saveCapacity($u->uid, BrandRules::capacityUpdate()->parse(Gate::body($r))));
    }

    public function quote(Request $r): Response
    {
        Gate::finance($r);

        return Gate::json(['quote' => BrandAdmin::quote(BrandRules::quoteInput()->parse(Gate::body($r)))]);
    }

    public function campaigns(Request $r): Response
    {
        $u = Gate::finance($r);
        $input = Z::strictObject(['after' => F7::uuid()->optional()])->parse(Gate::params($r));

        return Gate::json(BrandAdmin::campaigns($u->uid, $input['after'] ?? null, BrandMedia::enabled()), ['Cache-Control' => 'no-store']);
    }

    public function saveCampaign(Request $r): Response
    {
        $u = Gate::finance($r);

        return Gate::json(['item' => BrandAdmin::act($u->uid, BrandRules::action()->parse(Gate::body($r)))], ['Cache-Control' => 'no-store']);
    }

    public function reservation(Request $r): Response
    {
        Gate::finance($r);
        $a = BrandRules::campaignLookup()->parse(Gate::params($r));

        return Gate::json(['enabled' => F7::flag('brand_reservations')] + BrandInventory::read($a['campaignId']));
    }

    public function reserve(Request $r): Response
    {
        $u = Gate::finance($r);
        $a = BrandRules::reservationInput()->parse(Gate::body($r));
        if (! F7::flag('brand_reservations')) {
            throw new HttpError(503, 'Marka rezervasyonları henüz açılmadı.');
        }
        try {
            $reservation = BrandInventory::reserve($u->uid, (object) $a);
        } catch (Throwable) {
            throw new HttpError(409, 'Kapasite, onay veya tarife değişti. Bilgileri yenile.');
        }

        return Gate::json(['reservation' => $reservation]);
    }

    public function release(Request $r): Response
    {
        $u = Gate::finance($r);
        $a = BrandRules::campaignLookup()->parse(Gate::body($r));
        BrandInventory::releaseTx($a['campaignId'], $u->uid);

        return Gate::json(['released' => true]);
    }

    public function order(Request $r): Response
    {
        Gate::finance($r);
        $a = BrandRules::orderInput()->parse(Gate::params($r));
        $enabled = F7::flag('brand_orders');
        $ready = $enabled && PaymentGateway::configured() !== null;
        $state = $ready ? F7::row('brand_payment_states', 'reservation_id', $a['reservationId']) : null;

        return Gate::json(['enabled' => $enabled, 'checkoutEnabled' => $ready && ! in_array($state?->status, ['verified', 'needs_review'], true), 'order' => BrandOrders::read((object) $a)]);
    }

    public function prepareOrder(Request $r): Response
    {
        $u = Gate::finance($r);
        $a = BrandRules::orderInput()->parse(Gate::body($r));
        if (! F7::flag('brand_orders')) {
            throw new HttpError(503, 'Marka reklamı ödeme hazırlığı henüz açılmadı.');
        }
        try {
            $order = BrandOrders::create($u->uid, (object) $a);
        } catch (Throwable) {
            throw new HttpError(409, 'Rezervasyon veya fiyat değişti. Bilgileri yenile.');
        }

        return Gate::json(['order' => $order]);
    }
}
