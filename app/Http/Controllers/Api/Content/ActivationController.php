<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Activations;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActivationController
{
    use Guards;

    private const VERIFY = 'E-posta doğrulaması gerekli.';

    private const DENIED = 'Yetkiniz yok.';

    public function options(Request $r): Response
    {
        return Respond::options($r, 'GET, PATCH, OPTIONS');
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::activation($r);
            $u = $this->verified($r, self::VERIFY);
            $q = Query::from($r);
            if ($q->get('queue') === 'true') {
                if (! $u->admin || ! $u->emailVerified) {
                    throw new HttpError(403, self::DENIED);
                }
                $after = $q->get('after') ?? '';
                if ($after !== '' && preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $after) !== 1) {
                    throw new HttpError(400, 'Geçersiz istek.');
                }
                $status = $q->get('status') ?? 'pending';
                if (! in_array($status, ['pending', 'approved', 'rejected', 'paused'], true)) {
                    throw new HttpError(400, 'Geçersiz istek.');
                }

                return Respond::json(Activations::queue($status, $after === '' ? null : $after));
            }
            $bid = $q->get('businessId') ?? '';
            if (preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $bid) !== 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }

            return Respond::json(Activations::view($u, $bid));
        }, self::DENIED);
    }

    public function update(Request $r): Response
    {
        return Respond::guarded($r, function () use ($r): Response {
            HttpInput::activation($r);
            $u = $this->verified($r, self::VERIFY);
            Activations::act($u, Schemas::activationAction()->parse(Body::json($r)));

            return Respond::json(['ok' => true]);
        }, self::DENIED);
    }
}
