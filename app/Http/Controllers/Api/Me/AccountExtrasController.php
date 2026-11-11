<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Auth\ActorResolver;
use App\Domain\User\CoverageWaitlist;
use App\Domain\User\FavoriteLists;
use App\Domain\User\Preferences;
use App\Domain\User\Privacy;
use App\Domain\User\PrivacyExports;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\ClientSource;
use App\Support\Js;
use App\Support\Query;
use App\Support\RateLimit;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 2. tur hesap uclari (web-09, web-10, web-12, web-13). Sira: origin (route), sorgu, icerik turu, jeton, e-posta, limit, govde.
 * Origin modu `mobile`: web, portal ve native (Origin'siz) erisir.
 */
final class AccountExtrasController
{
    use Guards;

    // web-09
    public function preferences(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $u = ActorResolver::resolve($r);
        RateLimit::consume($u->uid);

        return Api::json(Preferences::read($u->uid));
    }

    public function updatePreferences(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = ActorResolver::resolve($r);
        RateLimit::consume($u->uid);

        return Api::json(Preferences::update($u, Preferences::schema()->parse(Body::json($r)), ClientSource::of($r)));
    }

    // web-12
    public function waitlist(Request $r): JsonResponse
    {
        $this->noQuery($r);

        return Api::json(CoverageWaitlist::list($this->verified($r)->uid));
    }

    public function joinWaitlist(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        $in = Z::strictObject(['city' => Z::string()->min(1)->max(80)])->parse(Body::json($r));
        $out = CoverageWaitlist::add($u->uid, $in['city']);

        return Api::json($out['item'], $out['created'] ? 201 : 200);
    }

    public function leaveWaitlist(Request $r): Response
    {
        $p = Query::from($r);
        $this->assertKeys($p, ['city']);
        $city = (string) $p->get('city');
        if ($city === '' || Js::length($city) > 80) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        CoverageWaitlist::remove($this->verified($r)->uid, $city);

        return Api::empty(204);
    }

    // web-13
    public function lists(Request $r): JsonResponse
    {
        $this->noQuery($r);

        return Api::json(FavoriteLists::list($this->verified($r)->uid));
    }

    public function createList(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        $out = FavoriteLists::create($u->uid, FavoriteLists::createSchema()->parse(Body::json($r)));

        return Api::json($out['list'], $out['created'] ? 201 : 200);
    }

    public function updateList(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);

        return Api::json(FavoriteLists::update($u->uid, FavoriteLists::updateSchema()->parse(Body::json($r))));
    }

    public function deleteList(Request $r): Response
    {
        $p = Query::from($r);
        $this->assertKeys($p, ['id']);
        $id = (string) $p->get('id');
        if (preg_match('/^[0-9a-f-]{36}$/D', $id) !== 1) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        FavoriteLists::delete($this->verified($r)->uid, $id);

        return Api::empty(204);
    }

    // web-10
    private function exportsOpen(): void
    {
        if (! config('otosanayide.flags.data_requests')) {
            throw new HttpError(503, 'Veri talepleri henüz kullanıma açılmadı.');
        }
    }

    public function exports(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $u = $this->verified($r);
        $this->exportsOpen();

        return Api::json(PrivacyExports::list($u->uid));
    }

    public function createExport(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        $this->exportsOpen();
        if (! Privacy::recentLogin($u->authTime, Js::nowMs())) {
            throw new HttpError(428, 'Güvenlik için çıkış yapıp tekrar giriş yap. Sonra talebini yeniden kontrol et.');
        }
        $in = Z::strictObject(['operationId' => Z::string()->uuid()])->parse(Body::json($r));
        $out = PrivacyExports::submit($u->uid, $in['operationId'], Js::nowMs());

        return Api::json(['request' => $out['request'], 'preparation' => 'manual'], $out['created'] ? 202 : 200);
    }

    public function cancelExport(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        $this->exportsOpen();
        $in = Z::strictObject(['id' => Z::string()->uuid(), 'expectedVersion' => Z::number()->int()->positive(), 'action' => Z::literal('cancel')])->parse(Body::json($r));

        return Api::json(['request' => PrivacyExports::cancel($u->uid, $in['id'], $in['expectedVersion']), 'preparation' => 'manual']);
    }
}
