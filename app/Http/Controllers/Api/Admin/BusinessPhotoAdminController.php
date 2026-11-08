<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\PhotoModeration;
use App\Domain\Auth\ActorResolver;
use App\Domain\Auth\Actor;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Http\Controllers\Api\Content\HttpInput;
use App\Http\Controllers\Api\Content\Respond;
use App\Support\Body;
use App\Support\Query;
use App\Support\RateLimit;
use App\Support\Z;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** businessPhotoRoute(r,'admin'): izin yoksa OPTIONS dahil 403 JSON. */
final class BusinessPhotoAdminController
{
    private static function user(Request $r): Actor
    {
        $u = ActorResolver::resolve($r);
        if (! $u->emailVerified || ! $u->admin) {
            throw new HttpError(403, 'Bu işlem için yetkiniz yok.');
        }
        RateLimit::consume($u->uid);

        return $u;
    }

    /** businessPhotoHttp(method, q, type, 'admin') */
    private static function input(Request $r): void
    {
        HttpInput::run(static function () use ($r): void {
            $q = Query::from($r);
            $get = $r->isMethod('GET');
            $allowed = $get ? ['status', 'after'] : [];
            foreach ($q->keys() as $k) {
                if (! in_array($k, $allowed, true) || $q->count($k) !== 1) {
                    throw new \RuntimeException('photo_query');
                }
            }
            if ($get) {
                if ($q->has('status')) {
                    Z::enum(['pending', 'approved'])->parse($q->get('status'));
                }
                $after = $q->get('after');
                if ($after !== null && $after !== '') {
                    Schemas::uuid()->parse($after);
                }
            }
            if ($r->isMethod('PATCH') && preg_match('/^application\/json(?:\s*;|$)/iD', (string) $r->headers->get('Content-Type')) !== 1) {
                throw new \RuntimeException('photo_content_type');
            }
        }, 'photo_content_type');
    }

    public function options(Request $r): Response
    {
        return Respond::guarded($r, static fn (): Response => Api::empty(204, ['Access-Control-Allow-Methods' => 'GET, PATCH, OPTIONS', 'Access-Control-Allow-Headers' => Respond::JSON_HEADERS, 'Access-Control-Max-Age' => '600']));
    }

    public function index(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            self::input($r);
            self::user($r);
            $q = Query::from($r);
            $after = $q->get('after');

            return Respond::json(PhotoModeration::businessPage($q->get('status') ?? 'pending', ($after !== null && $after !== '') ? $after : null));
        });
    }

    public function update(Request $r): Response
    {
        return Respond::guarded($r, static function () use ($r): Response {
            self::input($r);
            $u = self::user($r);
            PhotoModeration::decideBusiness($u, AdminSchemas::businessPhotoDecision()->parse(Body::json($r)));

            return Respond::json(['ok' => true]);
        });
    }
}
