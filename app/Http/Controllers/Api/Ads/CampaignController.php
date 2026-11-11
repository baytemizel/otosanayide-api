<?php

namespace App\Http\Controllers\Api\Ads;

use App\Domain\Ads\AdInventory;
use App\Domain\Ads\AdMetrics;
use App\Domain\Ads\AdRules;
use App\Domain\Ads\CampaignRules;
use App\Domain\Ads\Campaigns;
use App\Domain\Ads\OwnerAdFinance;
use App\Domain\Payments\F7;
use App\Domain\Payments\PaymentGateway;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Http\Controllers\Api\Content\Respond;
use App\Http\Controllers\Api\Payments\Gate;
use App\Support\Body;
use App\Support\Query;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** /api/campaigns, /campaigns/reservation, /campaigns/finance, /campaigns/owner-metrics, /campaigns/metrics */
final class CampaignController
{
    private const INVENTORY = 'Reklam fiyatı veya alanı uygun değil. Güncel bilgileri yeniden kontrol et.';

    private function input(Request $r): void
    {
        try {
            CampaignRules::http($r->method(), Query::from($r), $r->headers->get('Content-Type'));
        } catch (Throwable $e) {
            throw new HttpError(! $e instanceof ZodError && $e->getMessage() === 'campaign_content_type' ? 415 : 400, 'Geçersiz istek.');
        }
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            $this->input($r);
            $u = Gate::verified($r);

            return Api::json(Campaigns::list($u, Query::from($r)), 200, []);
        });
    }

    public function update(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            $this->input($r);
            $u = Gate::verified($r);
            $a = CampaignRules::action()->parse(Body::json($r));

            return Api::json(['item' => Campaigns::act($u, $a)], 200, ['Cache-Control' => 'no-store']);
        });
    }

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, PATCH, OPTIONS');
    }

    private function inventoryFailure(Throwable $e): Throwable
    {
        return AdInventory::known($e) ? new HttpError(409, self::INVENTORY) : $e;
    }

    public function quote(Request $r): Response
    {
        try {
            $u = Gate::verified($r);
            $p = Gate::params($r);
            $p->campaignVersion = Z::coerceNumber()->parse($p->campaignVersion ?? null);
            $input = AdRules::financeQuery()->parse($p);
            $enabled = F7::flag('ad_reservations');

            return Api::json(['quote' => AdInventory::quote($u->uid, $input), 'reservationsEnabled' => $enabled, 'checkoutEnabled' => $enabled && PaymentGateway::configured() !== null], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $e) {
            throw $this->inventoryFailure($e);
        }
    }

    public function reserve(Request $r): Response
    {
        try {
            $u = Gate::verified($r);
            if (! F7::flag('ad_reservations')) {
                throw new HttpError(503, 'Reklam rezervasyonu henüz etkin değil. Ücret alınmadı.');
            }
            $input = AdRules::reservation()->parse(Body::json($r));

            return Api::json(['reservation' => AdInventory::reserve($u->uid, $input)], 200, ['Cache-Control' => 'no-store']);
        } catch (Throwable $e) {
            throw $this->inventoryFailure($e);
        }
    }

    /** owner-ad-server.ts ownerAdRequest */
    private function owner(Request $r, bool $metrics): Response
    {
        if (! Respond::allowed($r)) {
            return Respond::cors($r, Gate::guard(fn () => throw new HttpError(403, Gate::FORBIDDEN)));
        }
        try {
            try {
                $query = OwnerAdFinance::http($r->method(), Query::from($r), $r->headers->get('Content-Type'));
            } catch (Throwable $e) {
                throw new HttpError(! $e instanceof ZodError && $e->getMessage() === 'ad_content_type' ? 415 : 400, 'Geçersiz istek.');
            }
            $u = Gate::verified($r);
            $reservations = F7::flag('ad_reservations');
            if ($metrics) {
                if ($query === null) {
                    throw new HttpError(400, 'Geçersiz istek.');
                }
                $value = OwnerAdFinance::metrics($query, AdMetrics::report($u->uid, $query['campaignId'], null, $query), AdMetrics::enabled());
            } elseif ($r->isMethod('GET')) {
                $value = OwnerAdFinance::read($u->uid, $query, $reservations, null, $reservations && PaymentGateway::configured() !== null);
            } else {
                $input = AdRules::reservation()->parse(Body::json($r));
                if (! $reservations) {
                    throw new HttpError(503, 'Reklam rezervasyonu henüz etkin değil. Ücret alınmadı.');
                }
                $value = ['campaignId' => $input['campaignId'], 'businessId' => $input['businessId'], 'campaignVersion' => $input['campaignVersion'], 'reservation' => AdInventory::reserve($u->uid, $input)];
            }

            return Respond::cors($r, Api::json($value, 200, []));
        } catch (Throwable $e) {
            $known = AdInventory::known($e) || (! $e instanceof ZodError && $e instanceof RuntimeException && in_array($e->getMessage(), ['access', 'version', 'capacity', 'tariff', 'schedule', 'inventory'], true));
            $failure = $known ? new HttpError($e->getMessage() === 'access' ? 403 : 409, self::INVENTORY) : $e;

            return Respond::cors($r, Gate::guard(fn () => throw $failure));
        }
    }

    public function finance(Request $r): Response
    {
        return $this->owner($r, false);
    }

    public function ownerMetrics(Request $r): Response
    {
        return $this->owner($r, true);
    }

    public function financeOptions(Request $r): Response
    {
        return Respond::options($r, 'GET, POST, OPTIONS');
    }

    public function ownerMetricsOptions(Request $r): Response
    {
        return Respond::options($r, 'GET, OPTIONS');
    }

    public function metrics(Request $r): Response
    {
        $u = Gate::verified($r, Gate::FORBIDDEN);
        $campaignId = AdRules::metricQuery()->parse(Gate::params($r))['campaignId'];
        try {
            $report = AdMetrics::report($u->uid, $campaignId);
        } catch (RuntimeException $e) {
            if (! $e instanceof ZodError && $e->getMessage() === 'access') {
                throw new HttpError(403, Gate::FORBIDDEN);
            }
            throw $e;
        }

        return Api::json($report + ['enabled' => AdMetrics::enabled()], 200, ['Cache-Control' => 'no-store']);
    }
}
