<?php

namespace App\Http\Controllers\Api\Brand;

use App\Domain\Brand\BrandAcceptance;
use App\Domain\Brand\BrandCancellation;
use App\Domain\Brand\BrandRefundReview;
use App\Domain\Brand\BrandRules;
use App\Domain\Payments\F7;
use App\Exceptions\HttpError;
use App\Http\Controllers\Api\Payments\Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** /api/admin/brand-acceptance, brand-cancellations, brand-cancellation-queue, brand-cancellation-history, brand-refund-review */
final class BrandFinanceController
{
    private const PRIVATE = ['Cache-Control' => 'private, no-store'];

    public function acceptance(Request $r): Response
    {
        Gate::finance($r);
        $input = BrandRules::orderInput()->parse(Gate::params($r));

        return Gate::json(BrandAcceptance::read((object) $input) + ['enabled' => F7::flag('brand_acceptance')]);
    }

    public function accept(Request $r): Response
    {
        $u = Gate::finance($r);
        $input = BrandRules::orderInput()->parse(Gate::body($r));
        if (! F7::flag('brand_acceptance')) {
            throw new HttpError(503, 'Marka reklamı kabul işlemleri henüz açılmadı.');
        }
        try {
            BrandAcceptance::accept($u->uid, (object) $input);
        } catch (Throwable) {
            throw new HttpError(409, 'Ödeme veya rezervasyon koşulları değişti. Kabul durumunu yenile.');
        }

        return Gate::json(BrandAcceptance::read((object) $input) + ['enabled' => true]);
    }

    private function cancellation(Request $r, string $method): Response
    {
        return Gate::guard(function () use ($r, $method): Response {
            $u = Gate::finance($r);
            if ($method === 'GET') {
                $id = BrandCancellation::lookup()->parse(Gate::params($r))['reservationId'];
            } else {
                Gate::sameOriginIfPresent($r);
                $input = ($method === 'POST' ? BrandCancellation::request() : BrandCancellation::decision())->parse(Gate::body($r));
                $id = $input['reservationId'];
                if (! F7::flag('brand_cancellations')) {
                    throw new HttpError(503, 'Yeni iptal işlemleri kapalı. Geçmiş kayıtlar okunabilir.');
                }
                try {
                    $method === 'POST' ? BrandCancellation::requestCancellation($u->uid, (object) $input) : BrandCancellation::decide($u->uid, (object) $input);
                } catch (Throwable) {
                    throw new HttpError(409, 'İptal koşulları değişti. Durumu yenile ve tekrar kontrol et.');
                }
            }

            return Gate::json(BrandCancellation::read($u->uid, (object) ['reservationId' => $id]) + ['enabled' => F7::flag('brand_cancellations')]);
        }, self::PRIVATE);
    }

    public function cancellationShow(Request $r): Response
    {
        return $this->cancellation($r, 'GET');
    }

    public function cancellationRequest(Request $r): Response
    {
        return $this->cancellation($r, 'POST');
    }

    public function cancellationDecide(Request $r): Response
    {
        return $this->cancellation($r, 'PATCH');
    }

    public function queue(Request $r): Response
    {
        return Gate::guard(function () use ($r): Response {
            $u = Gate::finance($r);
            $q = BrandCancellation::queueQuery()->parse(Gate::params($r));

            return Gate::json(BrandCancellation::queue($u->uid, (object) array_filter($q, static fn (mixed $v): bool => $v !== null)) + ['enabled' => F7::flag('brand_cancellations')]);
        }, self::PRIVATE);
    }

    public function history(Request $r): Response
    {
        return Gate::guard(function () use ($r): Response {
            $u = Gate::finance($r);
            $q = BrandCancellation::historyQuery()->parse(Gate::params($r));
            try {
                return Gate::json(BrandCancellation::history($u->uid, (object) $q));
            } catch (Throwable) {
                throw new HttpError(409, 'Geçmiş kaydı doğrulanamadı. Durumu yenile.');
            }
        }, self::PRIVATE);
    }

    private function refund(Request $r, bool $save): Response
    {
        return Gate::guard(function () use ($r, $save): Response {
            $u = Gate::finance($r);
            if ($save) {
                Gate::sameOriginIfPresent($r);
                $input = BrandRefundReview::action()->parse(Gate::body($r));
                if (! F7::flag('brand_refund_review')) {
                    throw new HttpError(503, 'Yeni uzlaştırma kararları kapalı. Geçmiş okunabilir.');
                }
                try {
                    BrandRefundReview::decide($u->uid, $input);
                } catch (Throwable) {
                    throw new HttpError(409, 'Uzlaştırma kaydı değişti. Güncel durumu yükle.');
                }
                $query = ['reservationId' => $input['reservationId'], 'eventId' => $input['eventId']];
            } else {
                $query = BrandRefundReview::query()->parse(Gate::params($r));
            }
            try {
                return Gate::json(BrandRefundReview::read($query) + ['enabled' => F7::flag('brand_refund_review')]);
            } catch (Throwable) {
                throw new HttpError(409, 'Uzlaştırma kaydı doğrulanamadı. Geçmişi yenile.');
            }
        }, self::PRIVATE);
    }

    public function refundShow(Request $r): Response
    {
        return $this->refund($r, false);
    }

    public function refundDecide(Request $r): Response
    {
        return $this->refund($r, true);
    }
}
