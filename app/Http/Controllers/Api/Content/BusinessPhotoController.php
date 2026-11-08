<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\BusinessPhotos;
use App\Domain\Content\Schemas;
use App\Http\Api;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** businessPhotoRoute: izin yoksa OPTIONS dahil 403 JSON; admin modu kapsam disi. */
final class BusinessPhotoController
{
    private static function preflight(Request $r, string $methods): Response
    {
        return Respond::guarded($r, static fn (): Response => Api::empty(204, ['Access-Control-Allow-Methods' => $methods, 'Access-Control-Allow-Headers' => Respond::JSON_HEADERS, 'Access-Control-Max-Age' => '600']));
    }

    public function options(Request $r): Response
    {
        return self::preflight($r, 'GET, POST, PATCH, OPTIONS');
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            HttpInput::businessPhoto($r, 'owner');
            $u = BusinessPhotos::user($r);

            return Respond::json(BusinessPhotos::list($u, (string) Query::from($r)->get('businessId')));
        });
    }

    public function store(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            HttpInput::businessPhoto($r, 'owner');
            $u = BusinessPhotos::user($r);
            $q = Query::from($r);

            return Respond::json(BusinessPhotos::upload($u, $r, (string) $q->get('businessId'), (string) $q->get('requestId')), 201);
        });
    }

    public function update(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            HttpInput::businessPhoto($r, 'owner');
            $u = BusinessPhotos::user($r);
            BusinessPhotos::action($u, Schemas::businessPhotoAction()->parse(Body::json($r)));

            return Respond::json(['ok' => true]);
        });
    }

    public function publicOptions(Request $r): Response
    {
        return self::preflight($r, 'GET, OPTIONS');
    }

    public function publicList(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            HttpInput::businessPhoto($r, 'public');

            return Respond::json(BusinessPhotos::publicList((string) Query::from($r)->get('businessId')));
        });
    }

    public function mediaOptions(Request $r): Response
    {
        return self::preflight($r, 'GET, OPTIONS');
    }

    public function media(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            HttpInput::businessPhoto($r, 'media');
            $q = Query::from($r);

            return Respond::image(BusinessPhotos::media($r, (string) $q->get('businessId'), (string) $q->get('id'), $q->has('size')), 'business-photo.webp');
        });
    }
}
