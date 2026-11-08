<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\ActorResolver;
use App\Domain\Content\Places;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\RateLimit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PlacesController
{
    public function options(Request $r): Response
    {
        return Respond::options($r, 'POST, OPTIONS');
    }

    public function store(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            $u = ActorResolver::resolve($r);
            if ((string) $r->server->get('QUERY_STRING', '') !== '' || preg_match('/^application\/json(?:\s*;|$)/iD', (string) $r->headers->get('Content-Type')) !== 1) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            if (! $u->emailVerified) {
                throw new HttpError(403, 'Doğrulanmış hesap gerekli.');
            }
            RateLimit::consume($u->uid);
            if (config('otosanayide.flags.google_places') !== true || Places::apiKey() === null) {
                throw new HttpError(503, 'Adres araması henüz yapılandırılmadı.');
            }

            return Respond::json(Places::search($u, Schemas::placeQuery()->parse(Body::json($r))));
        });
    }
}
