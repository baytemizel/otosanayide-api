<?php

namespace App\Console\Commands;

use App\Domain\Ops\ReminderInboxWorker;
use App\Domain\Ops\ScheduledRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** reminder_inbox: vadesi gelen hatirlatmalari uygulama ici kutuya yazar. Saglayici cagrilmaz. */
final class DeliverReminderInbox extends Command
{
    protected $signature = 'reminders:deliver-inbox {--apply : Kutu kaydi yazar ve sonraki kontrolu ilerletir}';

    protected $description = 'Vadesi gelen arac hatirlatmalarini uygulama ici kutuya yazar; --apply olmadan yalniz sayar.';

    public function handle(ReminderInboxWorker $worker): int
    {
        if (! $this->option('apply')) {
            $cursor = DB::table('scheduled_runs')->where('job', 'reminder_inbox')->value('cursor');
            $this->line((string) json_encode(['apply' => false] + $worker->run(false, null, is_string($cursor) ? $cursor : null)));

            return self::SUCCESS;
        }
        if (! config('otosanayide.workers.reminder_inbox')) {
            $this->error('REMINDER_INBOX_WORKER_ENABLED kapali.');

            return self::FAILURE;
        }
        try {
            $result = ScheduledRuns::page('reminder_inbox', function (?string $cursor) use ($worker): array {
                $r = $worker->run(true, null, $cursor);

                return ['next' => $r['next'], 'counts' => array_diff_key($r, ['next' => true])];
            });
        } catch (Throwable) {
            $this->error('Zamanlanmis calisma durdu: ops:scheduled-recover reminder_inbox ile durumu inceleyin. Kullanici verisi loglanmadi.');

            return self::FAILURE;
        }
        $this->line((string) json_encode(['state' => $result['state'], 'more' => $result['next'] !== null, 'counts' => $result['counts']]));

        return self::SUCCESS;
    }
}
