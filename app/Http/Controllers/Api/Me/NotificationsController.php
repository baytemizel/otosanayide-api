<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Account\AccountFence;
use App\Domain\Account\Membership;
use App\Domain\Auth\ActorResolver;
use App\Domain\User\RequestRules;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Js;
use App\Support\Query;
use App\Support\RateLimit;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

final class NotificationsController
{
    private function readSchema(): Z
    {
        return Z::strictObject(['id' => Z::string()->regex('/^[a-zA-Z0-9_-]{1,180}$/'), 'read' => Z::boolean()]);
    }

    private function view(stdClass $n, bool $read): array
    {
        if ($n->kind === 'reminder') {
            return ['id' => $n->id, 'kind' => 'reminder', 'vehicleLabel' => $n->vehicle_label ?? '', 'title' => $n->title ?? '', 'phase' => in_array($n->phase, ['advance', 'due', 'mileage'], true) ? $n->phase : 'due', 'dueDate' => $n->due_date, 'dueKm' => $n->due_km === null ? null : (int) $n->due_km, 'createdAtMs' => (int) $n->created_at_ms, 'read' => $read];
        }

        return ['id' => $n->id, 'requestId' => $n->request_id, 'status' => $n->request_status, 'createdAtMs' => (int) $n->created_at_ms, 'read' => $read];
    }

    public function index(Request $request): JsonResponse
    {
        $u = ActorResolver::resolve($request);
        RateLimit::consume($u->uid);
        $p = Query::from($request);
        $bid = $p->get('businessId');
        $after = $p->get('after');
        if ($after !== null && $after !== '') {
            Z::string()->regex('/^[a-zA-Z0-9_-]{1,180}$/')->parse($after);
        }
        if ($bid !== null && $bid !== '') {
            RequestRules::id()->parse($bid);
            if (! Membership::isMember($u->uid, Membership::find($u->uid, $bid))) {
                throw new HttpError(403, 'Bu işletme için yetkiniz yok.');
            }
        }
        $audience = ($bid !== null && $bid !== '') ? 'business:'.$bid : 'user:'.$u->uid;
        $q = DB::table('notifications')->where('audience_key', $audience);
        if ($after !== null && $after !== '') {
            $q->where('id', '>', $after);
        }
        $rows = $q->orderBy('id')->limit(21)->get();
        $visible = $rows->take(20)->values();
        $reads = DB::table('notification_reads')->where('user_id', $u->uid)->whereIn('notification_id', $visible->pluck('id'))->pluck('read', 'notification_id');

        return Api::json(['items' => $visible->map(fn (stdClass $n) => $this->view($n, (bool) ($reads[$n->id] ?? false)))->all(), 'next' => $rows->count() > 20 ? $visible[19]->id : null]);
    }

    public function update(Request $request): JsonResponse
    {
        $u = ActorResolver::resolve($request);
        RateLimit::consume($u->uid);
        $input = $this->readSchema()->parse(Body::json($request));
        Tx::run(function () use ($u, $input): void {
            AccountFence::assertWritable($u->uid);
            $n = DB::table('notifications')->where('id', $input['id'])->lockForUpdate()->first();
            if ($n === null) {
                throw new HttpError(404, 'Bildirim bulunamadı.');
            }
            if ($n->audience_key !== 'user:'.$u->uid) {
                $bid = RequestRules::id()->parse($n->business_id);
                if ($n->audience_key !== 'business:'.$bid || ! Membership::isMember($u->uid, Membership::find($u->uid, $bid, true))) {
                    throw new HttpError(403, 'Bu bildirim için yetkin yok.');
                }
            }
            DB::table('notification_reads')->updateOrInsert(['notification_id' => $input['id'], 'user_id' => $u->uid], ['read' => $input['read'], 'updated_at_ms' => Js::nowMs()]);
        });

        return Api::json(['ok' => true]);
    }
}
