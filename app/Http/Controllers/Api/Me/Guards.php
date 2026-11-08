<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Auth\Actor;
use App\Domain\Auth\ActorResolver;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Query;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Kaynak handler'larindaki ortak adimlar; sira her controller'da kaynakla ayni kalir. */
trait Guards
{
    private function verified(Request $request, string $message = 'Önce e-posta adresini doğrula.'): Actor
    {
        $u = ActorResolver::resolve($request);
        if (! $u->emailVerified) {
            throw new HttpError(403, $message);
        }
        RateLimit::consume($u->uid);

        return $u;
    }

    private function noQuery(Request $request): void
    {
        if ($request->server->get('QUERY_STRING', '') !== '') {
            throw new HttpError(400, 'Geçersiz istek.');
        }
    }

    private function jsonPrefix(Request $request): void
    {
        if (! str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            throw new HttpError(415, 'Geçersiz istek.');
        }
    }

    private function jsonStrict(Request $request): void
    {
        if (preg_match('/^application\/json(?:\s*;|$)/iD', (string) $request->headers->get('Content-Type')) !== 1) {
            throw new HttpError(415, 'Geçersiz istek.');
        }
    }

    /** @param list<string> $allowed */
    private function assertKeys(Query $p, array $allowed): void
    {
        foreach ($p->keys() as $key) {
            if (! in_array($key, $allowed, true) || $p->count($key) !== 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
        }
    }

    private function preflight(Request $request, string $methods, string $headers = 'Authorization, Content-Type, Accept-Language'): Response
    {
        $allowed = $request->attributes->get('origin_allowed') === true;

        return Api::empty($allowed ? 204 : 403, ['Cache-Control' => 'private, no-store', 'Vary' => 'Origin'] + ($allowed ? ['Access-Control-Allow-Methods' => $methods, 'Access-Control-Allow-Headers' => $headers, 'Access-Control-Max-Age' => '600'] : []));
    }
}
