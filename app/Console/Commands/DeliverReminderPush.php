<?php

namespace App\Console\Commands;

final class DeliverReminderPush extends DeliverReminderOutbound
{
    protected $signature = 'reminders:deliver-push {--apply : Hatirlatma push bildirimlerini gonderir}';

    protected $description = 'Kuyruktaki hatirlatma push bildirimlerini (en fazla 10 deneme/sayfa) gonderir; --apply olmadan yalniz sayar.';

    protected function channel(): string
    {
        return 'push';
    }

    protected function gates(): array
    {
        return ['REMINDER_PUSH_WORKER_ENABLED' => (bool) config('otosanayide.workers.reminder_push'), 'PUSH_DELIVERY_ENABLED' => (bool) config('otosanayide.flags.push_delivery')];
    }
}
