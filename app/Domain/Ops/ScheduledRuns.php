<?php

namespace App\Domain\Ops;

use App\Support\Js;
use App\Support\Tx;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * packages/server/scheduled-jobs.ts: is basina tek kayit, sayfa basina bir calisma.
 * - Kayit idle degilse (running/uncertain) yeni calisma reddedilir; lease dolmasi devralma izni DEGILDIR.
 * - Cursor ve sayfa sonucu ayni transaction'da kaydedilir.
 * - Hata veya lease asimi kaydi `uncertain` birakir; yalniz operator `ops:scheduled-recover` ile acar.
 * Laravel Schedule withoutOverlapping/onOneServer ikinci kilittir; bu defter yerine gecmez.
 */
final class ScheduledRuns
{
    public const JOBS = ['reminder_inbox', 'request_inbox', 'reminder_email', 'reminder_push', 'garage_recycle'];

    public static function binding(string $job): string
    {
        return hash('sha256', (string) json_encode(['schema' => 1, 'database' => config('database.connections.mysql.database'), 'job' => $job, 'contract' => 'scheduled-page-v1']));
    }

    /**
     * @param  Closure(?string):array{next:?string,counts:array<string,int>}  $work
     * @return array{state:string,next:?string,counts:array<string,int>}
     */
    public static function page(string $job, Closure $work, int $timeoutMs = 240000, ?Closure $clock = null): array
    {
        if (! in_array($job, self::JOBS, true) || $timeoutMs < 1 || $timeoutMs > 300000) {
            throw new RuntimeException('scheduler_job');
        }
        $clock ??= static fn (): int => Js::nowMs();
        $binding = self::binding($job);
        $runId = (string) Str::uuid();
        $admitted = Tx::run(static function () use ($job, $binding, $runId, $timeoutMs, $clock): array {
            $old = DB::table('scheduled_runs')->where('job', $job)->lockForUpdate()->first();
            if ($old !== null && $old->binding_digest !== $binding) {
                throw new RuntimeException('scheduler_binding');
            }
            if ($old !== null && $old->state !== 'idle') {
                throw new RuntimeException('scheduler_recovery_required');
            }
            $now = $clock();
            $row = ['binding_digest' => $binding, 'run_id' => $runId, 'state' => 'running', 'version' => (int) ($old->version ?? 0) + 1, 'cursor' => $old->cursor ?? null, 'updated_at_ms' => $now, 'lease_until_ms' => $now + $timeoutMs + 5000];
            if ($old === null) {
                DB::table('scheduled_runs')->insert(['job' => $job] + $row);
            } else {
                DB::table('scheduled_runs')->where('job', $job)->update($row);
            }

            return $row;
        });
        try {
            $result = $work($admitted['cursor']);
            if ($result['next'] !== null && $result['next'] === $admitted['cursor']) {
                throw new RuntimeException('scheduler_cursor_stalled');
            }
            Tx::run(static function () use ($job, $runId, $admitted, $result, $clock): void {
                $current = DB::table('scheduled_runs')->where('job', $job)->lockForUpdate()->first();
                $now = $clock();
                if ($current === null || $current->run_id !== $runId || (int) $current->version !== $admitted['version'] || $current->state !== 'running' || $now > (int) $current->lease_until_ms) {
                    throw new RuntimeException('scheduler_source_changed');
                }
                DB::table('scheduled_runs')->where('job', $job)->update([
                    'state' => 'idle', 'version' => (int) $current->version + 1, 'cursor' => $result['next'], 'last_counts' => json_encode($result['counts']),
                    'updated_at_ms' => $now, 'lease_until_ms' => 0, 'completed_pages' => (int) $current->completed_pages + 1,
                    'completed_sweeps' => (int) $current->completed_sweeps + ($result['next'] === null ? 1 : 0),
                ]);
            });

            return ['state' => 'completed_page', 'next' => $result['next'], 'counts' => $result['counts']];
        } catch (Throwable) {
            try {
                Tx::run(static function () use ($job, $runId, $clock): void {
                    $current = DB::table('scheduled_runs')->where('job', $job)->lockForUpdate()->first();
                    if ($current !== null && $current->run_id === $runId && $current->state === 'running') {
                        DB::table('scheduled_runs')->where('job', $job)->update(['state' => 'uncertain', 'version' => (int) $current->version + 1, 'updated_at_ms' => $clock()]);
                    }
                });
            } catch (Throwable) {
                // Kalici running de engeldir; otomatik tekrar yok.
            }
            throw new RuntimeException('scheduler_recovery_required');
        }
    }

    /** Operator kurtarmasi: tam surum eslesmesi + calisanin durdugu ve dis etkilerin incelendigi beyan edilir. */
    public static function recover(string $job, int $expectedVersion, bool $resetCursor, string $operator): void
    {
        Tx::run(static function () use ($job, $expectedVersion, $resetCursor, $operator): void {
            $current = DB::table('scheduled_runs')->where('job', $job)->lockForUpdate()->first();
            if ($current === null || $current->state === 'idle' || (int) $current->version !== $expectedVersion) {
                throw new RuntimeException('scheduler_review_changed');
            }
            DB::table('scheduled_runs')->where('job', $job)->update(['state' => 'idle', 'version' => $expectedVersion + 1, 'lease_until_ms' => 0, 'updated_at_ms' => Js::nowMs(), 'recovered_by' => $operator] + ($resetCursor ? ['cursor' => null] : []));
        });
    }
}
