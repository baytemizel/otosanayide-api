<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Auth\ActorResolver;
use App\Domain\Auth\AuthClient;
use App\Domain\Content\BusinessInsights;
use App\Domain\Content\BusinessReports;
use App\Domain\Content\BusinessTeam;
use App\Domain\User\AppointmentCapacity;
use App\Domain\User\RequestRules;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Http\Controllers\Api\Me\Guards;
use App\Support\Body;
use App\Support\Js;
use App\Support\Query;
use App\Support\RateLimit;
use App\Support\Throttle;
use App\Support\Z;
use App\Support\ZodError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** 2. tur isletme uclari: web-04, portal-01, portal-02, portal-03. Hicbiri /api/admin altinda degildir. */
final class BusinessToolsController
{
    use Guards;

    private function businessQuery(Request $r, array $extra = []): Query
    {
        $p = Query::from($r);
        $this->assertKeys($p, ['businessId', ...$extra]);
        try {
            RequestRules::id()->parse($p->get('businessId'));
        } catch (ZodError) {
            throw new HttpError(400, 'Geçersiz istek.');
        }

        return $p;
    }

    private function bid(string $id): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $id) !== 1) {
            throw new HttpError(404, 'İşletme bulunamadı.');
        }

        return $id;
    }

    // web-04
    public function report(Request $r, string $id): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        Throttle::hit('business-report', $u->uid, 10, 86400);
        $out = BusinessReports::submit($u->uid, $this->bid($id), BusinessReports::schema()->parse(Body::json($r)));

        return Api::json($out['report'], $out['created'] ? 201 : 200);
    }

    // portal-02
    public function insights(Request $r): JsonResponse
    {
        $p = $this->businessQuery($r, ['days']);
        $days = $p->get('days') ?? '30';
        if (! in_array($days, ['7', '30'], true)) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $u = $this->verified($r);

        return Api::json(BusinessInsights::read($u->uid, (string) $p->get('businessId'), (int) $days));
    }

    // portal-01
    public function team(Request $r): JsonResponse
    {
        $p = $this->businessQuery($r);
        $u = $this->verified($r);

        return Api::json(BusinessTeam::read($u, (string) $p->get('businessId')));
    }

    public function invite(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        Throttle::hit('team-invite', $u->uid, 20, 86400);
        $in = Z::strictObject(['businessId' => RequestRules::id(), 'email' => Z::string()->trim()->max(254)->email()])->parse(Body::json($r));
        $email = (string) normalizer_normalize(mb_strtolower($in['email'], 'UTF-8'), \Normalizer::FORM_C);

        return Api::json(BusinessTeam::invite($u, $in['businessId'], $email, AuthClient::linkOrigin($r)), 201);
    }

    public function acceptInvitation(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        Throttle::hit('team-accept', $u->uid, 20, 3600);
        $in = Z::strictObject(['token' => Z::string()->min(1)->max(128)])->parse(Body::json($r));

        return Api::json(BusinessTeam::accept($u, $in['token']));
    }

    public function changeTeam(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);
        $member = ['businessId' => RequestRules::id(), 'memberId' => Z::string()->regex('/^[A-Za-z0-9_-]{1,128}$/'), 'expectedVersion' => Z::number()->int()->nonnegative()];
        $in = Z::discriminatedUnion('action', [
            'suspend' => Z::strictObject($member + ['action' => Z::literal('suspend')]),
            'resume' => Z::strictObject($member + ['action' => Z::literal('resume')]),
            'remove' => Z::strictObject($member + ['action' => Z::literal('remove')]),
            'cancel_invitation' => Z::strictObject(['businessId' => RequestRules::id(), 'invitationId' => Z::string()->uuid(), 'action' => Z::literal('cancel_invitation')]),
        ])->parse(Body::json($r));

        return Api::json(BusinessTeam::change($u, $in));
    }

    // portal-03
    public function capacity(Request $r): JsonResponse
    {
        $p = $this->businessQuery($r);
        $u = $this->verified($r);

        return Api::json(AppointmentCapacity::read($u->uid, (string) $p->get('businessId')));
    }

    public function updateCapacity(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);

        return Api::json(AppointmentCapacity::update($u, AppointmentCapacity::settingsSchema()->parse(Body::json($r))));
    }

    public function addClosure(Request $r): JsonResponse
    {
        $this->noQuery($r);
        $this->jsonStrict($r);
        $u = $this->verified($r);

        return Api::json(AppointmentCapacity::addClosure($u, AppointmentCapacity::closureSchema()->parse(Body::json($r))), 201);
    }

    public function removeClosure(Request $r): Response
    {
        $p = $this->businessQuery($r, ['id']);
        $id = (string) $p->get('id');
        if (preg_match('/^[0-9a-f-]{36}$/D', $id) !== 1) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        AppointmentCapacity::removeClosure($this->verified($r), (string) $p->get('businessId'), $id);

        return Api::empty(204);
    }

    /** Herkese acik: jeton istemez. IP basina genel hiz siniri. */
    public function availability(Request $r, string $id): JsonResponse
    {
        $p = Query::from($r);
        $this->assertKeys($p, ['from', 'days']);
        $from = $p->get('from') ?? AppointmentCapacity::today();
        $days = $p->get('days') ?? '7';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) !== 1 || ! checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4))
            || preg_match('/^(?:[1-9]|1[0-4])$/D', $days) !== 1 || strcmp($from, AppointmentCapacity::today()) < 0) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        RateLimit::consume('ip:'.$r->ip());

        return Api::json(AppointmentCapacity::availability($this->bid($id), $from, (int) $days), 200, ['Cache-Control' => 'no-store']);
    }
}
