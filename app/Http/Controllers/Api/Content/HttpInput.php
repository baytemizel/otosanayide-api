<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Support\Query;
use App\Support\Z;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/** *HttpInput fonksiyonlari; atilan her hata 400, icerik tipi hatasi 415 olur. */
final class HttpInput
{
    private const JSON = '/^application\/json(?:\s*;|$)/iD';

    private static function type(Request $r): ?string
    {
        return $r->headers->get('Content-Type');
    }

    private static function json(?string $type): bool
    {
        return preg_match(self::JSON, $type ?? '') === 1;
    }

    /**
     * @param  Closure():void  $check
     */
    public static function run(Closure $check, string $contentTypeCode): void
    {
        try {
            $check();
        } catch (Throwable $e) {
            throw new HttpError($e->getMessage() === $contentTypeCode ? 415 : 400, 'Geçersiz istek.');
        }
    }

    private static function fail(string $code): never
    {
        throw new RuntimeException($code);
    }

    private static function eachOnce(Query $q, array $allowed, string $code): void
    {
        foreach ($q->keys() as $k) {
            if (! in_array($k, $allowed, true) || $q->count($k) !== 1) {
                self::fail($code);
            }
        }
    }

    public static function authorReview(Request $r): void
    {
        self::run(static function () use ($r): void {
            $q = Query::from($r);
            if ($r->isMethod('GET')) {
                if (count($q->keys()) !== 1 || $q->count('businessId') !== 1) {
                    self::fail('review_query');
                }
                Schemas::id()->parse($q->get('businessId'));
            } else {
                if (! $q->isEmpty()) {
                    self::fail('review_query');
                }
                if (! self::json(self::type($r))) {
                    self::fail('review_content_type');
                }
            }
        }, 'review_content_type');
    }

    /** publicReviewQuery: cagiran hatayi 400'e cevirir. */
    public static function publicReview(Request $r): array
    {
        $q = Query::from($r);
        self::eachOnce($q, ['businessId', 'after'], 'review_query');
        $after = $q->get('after');

        return ['businessId' => Schemas::id()->parse($q->get('businessId')), 'after' => ($after !== null && $after !== '') ? Schemas::id()->parse($after) : null];
    }

    public static function cases(Request $r): void
    {
        self::run(static function () use ($r): void {
            $q = Query::from($r);
            if ($r->isMethod('GET')) {
                self::eachOnce($q, ['after'], 'case_query');
                $after = $q->get('after');
                if ($after !== null && $after !== '') {
                    Schemas::id()->parse($after);
                }
            } else {
                if (! $q->isEmpty()) {
                    self::fail('case_query');
                }
                if (! self::json(self::type($r))) {
                    self::fail('case_content_type');
                }
            }
        }, 'case_content_type');
    }

    public static function ownPhoto(Request $r): void
    {
        self::run(static function () use ($r): void {
            $q = Query::from($r);
            $method = $r->method();
            $keys = $method === 'GET' ? ['reviewId', 'version'] : ($method === 'POST' ? ['reviewId', 'version', 'rights'] : []);
            self::eachOnce($q, $keys, 'photo_query');
            if ($method !== 'PATCH') {
                Schemas::id()->parse($q->get('reviewId'));
                if ($q->has('version')) {
                    if (preg_match('/^[1-9]\d{0,15}$/D', (string) $q->get('version')) !== 1) {
                        self::fail('photo_query');
                    }
                    Z::number()->int()->positive()->max(9007199254740991)->parse((int) $q->get('version'));
                }
            }
            if ($method === 'POST' && ($q->get('rights') !== 'yes' || ! in_array(self::type($r) ?? '', ['image/jpeg', 'image/png', 'image/webp'], true))) {
                self::fail('photo_content_type');
            }
            if ($method === 'PATCH' && ! self::json(self::type($r))) {
                self::fail('photo_content_type');
            }
        }, 'photo_content_type');
    }

    /** @return array{id:string,thumbnail:bool} */
    public static function reviewPhotoQuery(Request $r): array
    {
        $q = Query::from($r);
        self::eachOnce($q, ['id', 'size'], 'photo_query');
        $id = Schemas::uuid()->parse($q->get('id'));
        $size = $q->get('size');
        if ($size !== null && ! in_array($size, ['thumbnail', 'full'], true)) {
            self::fail('photo_query');
        }

        return ['id' => $id, 'thumbnail' => $size === 'thumbnail'];
    }

    /** @param 'owner'|'public'|'media' $mode */
    public static function businessPhoto(Request $r, string $mode): void
    {
        self::run(static function () use ($r, $mode): void {
            $q = Query::from($r);
            $method = $r->method();
            $keys = $mode === 'media' ? ['businessId', 'id', 'size'] : ($mode === 'public' ? ['businessId'] : ($method === 'POST' ? ['businessId', 'requestId', 'rights'] : ($method === 'GET' ? ['businessId'] : [])));
            self::eachOnce($q, $keys, 'photo_query');
            if ($mode === 'media') {
                Schemas::id()->parse($q->get('businessId'));
                Schemas::uuid()->parse($q->get('id'));
                if ($q->has('size')) {
                    Z::literal('thumbnail')->parse($q->get('size'));
                }
            } elseif ($mode === 'public' || $method !== 'PATCH') {
                Schemas::id()->parse($q->get('businessId'));
                if ($method === 'POST') {
                    Schemas::uuid()->parse($q->get('requestId'));
                    Z::literal('yes')->parse($q->get('rights'));
                }
            }
            if ($method === 'POST' && ! in_array(self::type($r) ?? '', ['image/jpeg', 'image/webp'], true)) {
                self::fail('photo_content_type');
            }
            if ($method === 'PATCH' && ! self::json(self::type($r))) {
                self::fail('photo_content_type');
            }
        }, 'photo_content_type');
    }

    public static function console(Request $r, bool $branches = false): void
    {
        self::run(static function () use ($r, $branches): void {
            $q = Query::from($r);
            $method = $branches ? 'GET' : $r->method();
            $keys = $branches ? ['after', 'scope'] : ($method === 'GET' ? ['businessId', 'after'] : []);
            self::eachOnce($q, $keys, 'console_query');
            if ($method === 'GET') {
                if ($branches && $q->has('scope') && ! in_array($q->get('scope'), ['profile', 'activation', 'campaigns'], true)) {
                    self::fail('console_scope');
                }
                if (! $branches) {
                    Schemas::id()->parse($q->get('businessId'));
                }
                $after = $q->get('after');
                if ($after !== null && $after !== '') {
                    ($branches ? Schemas::membershipCursor() : Schemas::id())->parse($after);
                }
            } elseif (! self::json(self::type($r))) {
                self::fail('console_content_type');
            }
        }, 'console_content_type');
    }

    public static function activation(Request $r): void
    {
        self::run(static function () use ($r): void {
            $q = Query::from($r);
            if ($r->isMethod('GET')) {
                $queue = $q->get('queue') === 'true';
                self::eachOnce($q, $queue ? ['queue', 'status', 'after'] : ['businessId'], 'activation_query');
                if ($queue) {
                    if ($q->has('status')) {
                        Z::enum(['pending', 'approved', 'rejected', 'paused'])->parse($q->get('status'));
                    }
                    $after = $q->get('after');
                    if ($after !== null && $after !== '') {
                        Schemas::id()->parse($after);
                    }
                } else {
                    Schemas::id()->parse($q->get('businessId'));
                }
            } else {
                if ($q->keys() !== []) {
                    self::fail('activation_query');
                }
                if (! self::json(self::type($r))) {
                    self::fail('activation_content_type');
                }
            }
        }, 'activation_content_type');
    }

    public static function profile(Request $r): void
    {
        self::run(static function () use ($r): void {
            $q = Query::from($r);
            $get = $r->isMethod('GET');
            foreach ($q->keys() as $k) {
                // portal-04: v=2 saat alanlarini acar.
                if (! $get || ! in_array($k, ['businessId', 'v'], true) || $q->count($k) !== 1 || ($k === 'v' && $q->get('v') !== '2')) {
                    self::fail('profile_query');
                }
            }
            if ($get && $q->has('businessId')) {
                Schemas::id()->parse($q->get('businessId'));
            }
            if (! $get && ! self::json(self::type($r))) {
                self::fail('profile_content_type');
            }
        }, 'profile_content_type');
    }
}
