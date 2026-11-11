<?php

namespace App\Http\Controllers\Api\Payments;

use App\Domain\Brand\BrandReview;
use App\Domain\Payments\Checkout;
use App\Domain\Payments\F7;
use App\Domain\Payments\PaymentCases;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\Webhook;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Http\Controllers\Api\Content\Respond;
use App\Support\Body;
use App\Support\ZodError;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** /api/payments/checkout, /api/payments/webhook, /api/admin/payment-cases, /api/admin/brand-payment-review */
final class PaymentController
{
    public function checkout(Request $r): Response
    {
        try {
            if (! Respond::allowed($r) || $r->server->get('QUERY_STRING', '') !== '') {
                throw new HttpError(403, Gate::FORBIDDEN);
            }
            $u = Gate::verified($r);
            if (preg_match('/^application\/json(?:\s*;|$)/iD', (string) $r->headers->get('Content-Type')) !== 1) {
                throw new HttpError(415, 'Geçersiz istek.');
            }
            $config = PaymentGateway::configured();
            if ($config === null) {
                throw new HttpError(503, 'Kartla ödeme henüz etkin değil. Kart bilgisi istenmez ve bu ekrandan ücret alınmaz.');
            }
            $command = Checkout::command()->parse(Body::json($r));
            if ($command['kind'] === 'business' ? ! F7::flag('ad_reservations') : ! F7::flag('brand_orders')) {
                throw new HttpError(503, 'Ödeme hazırlığı henüz etkin değil.');
            }
            $brandFinance = Gate::financeAllowed($u);
            $session = Checkout::prepare(['uid' => $u->uid, 'brandFinance' => $brandFinance], (object) $command, $config['gateway']);

            return Respond::cors($r, Api::json(['kind' => $command['kind'], 'orderId' => $command['reservationId'], 'session' => $session], 200, []));
        } catch (Throwable $error) {
            $e = ! $error instanceof ZodError && ! $error instanceof HttpError && preg_match('/^(?:checkout_|payment_)/', $error->getMessage()) === 1 ? new HttpError(409, 'Ödeme hazırlığı tamamlanamadı. Güncel rezervasyonu ve ödeme durumunu kontrol et.') : $error;

            return Respond::cors($r, Gate::guard(fn () => throw $e));
        }
    }

    public function checkoutOptions(Request $r): Response
    {
        return Respond::options($r, 'POST, OPTIONS');
    }

    /** Webhook: imza ham govde uzerinden; dogrulanamayan istek 401, islenemeyen 503 ({received:false}). */
    public function webhook(Request $r): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $config = PaymentGateway::configured();
        if ($config === null) {
            return Api::json(['received' => false], 503, $headers);
        }
        try {
            if ($r->server->get('QUERY_STRING', '') !== '' || preg_match('/^application\/json(?:\s*;|$)/iD', (string) $r->headers->get('Content-Type')) !== 1) {
                throw new \RuntimeException('signature');
            }
            $event = PaymentGateway::verifyWebhook(Body::bytes($r, 20000), (string) $r->headers->get('X-Payment-Timestamp', ''), (string) $r->headers->get('X-Payment-Signature', ''), $config['webhookSecret']);
        } catch (Throwable) {
            return Api::json(['received' => false], 401, $headers);
        }
        try {
            $result = Webhook::apply($event, $config['gateway']);
            if (! $result['replayed']) {
                try {
                    Checkout::reconcile($event['kind'], $event['orderId'], $config['gateway']);
                } catch (Throwable) {
                    // Checkout bagimsiz dogrulanmis kapanisa kadar tutulur; odeme makbuzu yine esastir.
                }
            }

            return Api::json($result, 200, $headers);
        } catch (Throwable) {
            return Api::json(['received' => false], 503, $headers);
        }
    }

    public function cases(Request $r): Response
    {
        Gate::finance($r);
        $input = PaymentCases::query()->parse(Gate::params($r));

        return Gate::json(PaymentCases::list($input['after'] ?? null), ['Cache-Control' => 'no-store']);
    }

    public function decideCase(Request $r): Response
    {
        $u = Gate::finance($r);
        $input = PaymentCases::action()->parse(Gate::body($r));

        return Gate::json(['item' => PaymentCases::decide($u->uid, $input)], ['Cache-Control' => 'no-store']);
    }

    public function brandReview(Request $r): Response
    {
        Gate::finance($r);
        $input = BrandReview::query()->parse(Gate::params($r));

        return Gate::json(BrandReview::read($input));
    }

    public function decideBrandReview(Request $r): Response
    {
        $u = Gate::finance($r);
        $input = Gate::body($r);
        try {
            $decision = BrandReview::decide($u->uid, $input);
        } catch (Throwable) {
            throw new HttpError(409, 'Kayıt değişti veya karar geçersiz. Kuyruğu yenile.');
        }

        return Gate::json(['decision' => $decision]);
    }
}
