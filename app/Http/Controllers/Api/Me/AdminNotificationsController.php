<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Account\AccountFence;
use App\Domain\Auth\Actor;
use App\Domain\Auth\ActorResolver;
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
use Illuminate\Support\Str;
use stdClass;

final class AdminNotificationsController
{
    private function admin(Request $request): Actor
    {
        $u = ActorResolver::resolve($request);
        if (! $u->emailVerified || ! $u->admin) {
            throw new HttpError(403, 'Yönetici yetkisi gerekli.');
        }
        RateLimit::consume($u->uid);

        return $u;
    }

    private function id(): Z
    {
        return Z::string()->regex('/^[a-zA-Z0-9_-]{1,180}$/');
    }

    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $p = Query::from($request);
        $state = Z::enum(['pending', 'processing', 'retry', 'failed', 'delivered', 'superseded'])->parse($p->get('state') ?? 'failed');
        $after = $p->get('after');
        if ($after !== null && $after !== '') {
            $this->id()->parse($after);
        }
        $q = DB::table('notification_jobs')->where('state', $state);
        if ($after !== null && $after !== '') {
            $q->where('id', '>', $after);
        }
        $rows = $q->orderBy('id')->limit(21)->get();
        $page = $rows->take(20)->values();

        return Api::json(['items' => $page->map(fn (stdClass $d) => ['id' => $d->id, 'state' => $d->state, 'attempts' => (int) $d->attempts, 'lastErrorCode' => $d->last_error_code ?? '', 'channel' => 'in_app'])->all(), 'next' => $rows->count() > 20 ? $page[19]->id : null]);
    }

    public function update(Request $request): JsonResponse
    {
        $u = $this->admin($request);
        $input = Z::strictObject(['id' => $this->id(), 'reason' => Z::string()->trim()->min(10)->max(1000)])->parse(Body::json($request));
        Tx::run(function () use ($u, $input): void {
            $j = DB::table('notification_jobs')->where('id', $input['id'])->lockForUpdate()->first();
            if ($j === null || $j->state !== 'failed') {
                throw new HttpError(409, 'Yalnızca başarısız iş yeniden kuyruğa alınabilir.');
            }
            AccountFence::assertWritable($u->uid);
            if ($j->customer_id !== $u->uid) {
                AccountFence::assertWritable($j->customer_id);
            }
            $now = Js::nowMs();
            DB::table('notification_jobs')->where('id', $input['id'])->update(['state' => 'pending', 'attempts' => 0, 'next_attempt_at_ms' => $now, 'lease_id' => null, 'lease_until_ms' => null, 'updated_at_ms' => $now]);
            DB::table('notification_job_history')->insert(['job_id' => $input['id'], 'id' => (string) Str::uuid(), 'action' => 'manual_retry', 'reason' => $input['reason'], 'actor_id' => $u->uid, 'created_at_ms' => $now]);
        });

        return Api::json(['ok' => true]);
    }
}
