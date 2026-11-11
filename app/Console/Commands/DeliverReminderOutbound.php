<?php

namespace App\Console\Commands;

use App\Domain\Ops\ReminderOutboundWorker;
use App\Domain\Ops\ScheduledRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** reminder_email / reminder_push ortak govdesi. Kuru calisma veritabanina yazmaz ve saglayiciya baglanmaz. */
abstract class DeliverReminderOutbound extends Command
{
    abstract protected function channel(): string;

    /** @return array<string,bool> bayrak adi => deger */
    abstract protected function gates(): array;

    public function handle(ReminderOutboundWorker $worker): int
    {
        $job = 'reminder_'.$this->channel();
        if (! $this->option('apply')) {
            $cursor = DB::table('scheduled_runs')->where('job', $job)->value('cursor');
            $page = $worker->page($this->channel(), is_string($cursor) ? $cursor : null, false);
            $this->line((string) json_encode(['apply' => false, 'pending' => $page['counts']['scanned']]));

            return self::SUCCESS;
        }
        foreach ($this->gates() as $name => $open) {
            if (! $open) {
                $this->error($name.' kapali.');

                return self::FAILURE;
            }
        }
        try {
            $result = ScheduledRuns::page($job, fn (?string $cursor): array => $worker->page($this->channel(), $cursor, true));
        } catch (Throwable) {
            $this->error('Zamanlanmis calisma durdu: ops:scheduled-recover '.$job.' ile durumu inceleyin. Sir, alici veya jeton loglanmadi.');

            return self::FAILURE;
        }
        $this->line((string) json_encode(['state' => $result['state'], 'more' => $result['next'] !== null, 'counts' => $result['counts']]));

        return self::SUCCESS;
    }
}
