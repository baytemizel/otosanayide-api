<?php

namespace App\Domain\Ops;

use App\Domain\Account\AccountFence;
use App\Domain\User\RequestRules;
use App\Support\Js;
use App\Support\Tx;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/** packages/server/request-notifications.ts: saglayici cagrilmaz, uygulama ici kutu kaydi yazilir. */
final class RequestNotificationWorker
{
    private const PAGE = 50;

    /** @return array<string,int> */
    public function run(bool $apply): array
    {
        $now = Js::nowMs();
        $rows = DB::table('notification_jobs')->where('next_attempt_at_ms', '<=', $now)->orderBy('next_attempt_at_ms')->orderBy('id')->limit(self::PAGE)->get();
        $result = ['scanned' => $rows->count(), 'delivered' => 0, 'superseded' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0, 'paused' => 0];
        if (! $apply) {
            return $result;
        }
        foreach ($rows as $row) {
            $leaseId = (string) Str::uuid();
            $claimed = Tx::run(fn () => $this->claim($row->id, $leaseId));
            if ($claimed !== 'processing') {
                $key = $claimed === 'failed' ? 'failed' : ($claimed === 'paused' ? 'paused' : 'skipped');
                $result[$key]++;

                continue;
            }
            try {
                $result[Tx::run(fn () => $this->deliver($row->id, $leaseId))]++;
            } catch (Throwable) {
                $result[Tx::run(fn () => $this->retry($row->id, $leaseId))]++;
            }
        }

        return $result;
    }

    private function job(stdClass $j): array
    {
        return ['state' => $j->state, 'attempts' => (int) $j->attempts, 'nextAttemptAtMs' => $j->next_attempt_at_ms === null ? null : (int) $j->next_attempt_at_ms];
    }

    private function claim(string $id, string $leaseId): string|false
    {
        $j = DB::table('notification_jobs')->where('id', $id)->lockForUpdate()->first();
        if ($j === null) {
            return false;
        }
        if (AccountFence::paused($j->customer_id)) {
            return 'paused';
        }
        $now = Js::nowMs();
        $next = RequestRules::claim($this->job($j), $now, $leaseId);
        if ($next === null) {
            return false;
        }
        $update = ['state' => $next['state'], 'updated_at_ms' => $now];
        if ($next['state'] === 'failed') {
            $update += ['next_attempt_at_ms' => null, 'lease_id' => null, 'lease_until_ms' => null];
        } else {
            $update += ['attempts' => $next['attempts'], 'lease_id' => $next['leaseId'], 'lease_until_ms' => $next['leaseUntilMs'], 'next_attempt_at_ms' => $next['nextAttemptAtMs']];
        }
        DB::table('notification_jobs')->where('id', $id)->update($update);

        return $next['state'];
    }

    private function owns(?stdClass $j, string $leaseId, int $now): bool
    {
        return $j !== null && $j->state === 'processing' && $j->lease_id === $leaseId && (int) $j->lease_until_ms > $now;
    }

    private function deliver(string $id, string $leaseId): string
    {
        $j = DB::table('notification_jobs')->where('id', $id)->lockForUpdate()->first();
        $now = Js::nowMs();
        if (! $this->owns($j, $leaseId, $now)) {
            return 'skipped';
        }
        if (AccountFence::paused($j->customer_id)) {
            return 'paused';
        }
        $r = DB::table('requests')->where('id', $j->request_id)->sharedLock()->first();
        $clear = ['next_attempt_at_ms' => null, 'lease_id' => null, 'lease_until_ms' => null];
        if ($r === null || $r->business_id !== $j->business_id || $r->user_id !== $j->customer_id || (int) $r->version !== (int) $j->request_version || $r->status !== $j->request_status) {
            DB::table('notification_jobs')->where('id', $id)->update(['state' => 'superseded', 'updated_at_ms' => $now] + $clear);

            return 'superseded';
        }
        foreach ([$id.'_customer' => 'user:'.$j->customer_id, $id.'_business' => 'business:'.$j->business_id] as $nid => $audience) {
            DB::table('notifications')->insertOrIgnore(['id' => $nid, 'audience_key' => $audience, 'business_id' => $j->business_id, 'request_id' => $j->request_id, 'request_status' => $j->request_status, 'request_version' => $j->request_version, 'created_at_ms' => $j->created_at_ms, 'delivered_at_ms' => $now]);
        }
        DB::table('notification_jobs')->where('id', $id)->update(['state' => 'delivered', 'channel' => 'in_app', 'delivered_at_ms' => $now] + $clear);

        return 'delivered';
    }

    private function retry(string $id, string $leaseId): string
    {
        $j = DB::table('notification_jobs')->where('id', $id)->lockForUpdate()->first();
        $now = Js::nowMs();
        if (! $this->owns($j, $leaseId, $now)) {
            return 'skipped';
        }
        if (AccountFence::paused($j->customer_id)) {
            return 'paused';
        }
        $next = RequestRules::retry($this->job($j), $now);
        DB::table('notification_jobs')->where('id', $id)->update(['state' => $next['state'], 'lease_id' => null, 'lease_until_ms' => null, 'next_attempt_at_ms' => $next['nextAttemptAtMs'] ?? null, 'last_error_code' => 'inbox_write_failed', 'updated_at_ms' => $now]);

        return $next['state'];
    }
}
