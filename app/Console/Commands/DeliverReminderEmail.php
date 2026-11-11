<?php

namespace App\Console\Commands;

final class DeliverReminderEmail extends DeliverReminderOutbound
{
    protected $signature = 'reminders:deliver-email {--apply : Hatirlatma e-postalarini gonderir}';

    protected $description = 'Kuyruktaki hatirlatma e-postalarini (en fazla 10 deneme/sayfa) gonderir; --apply olmadan yalniz sayar.';

    protected function channel(): string
    {
        return 'email';
    }

    protected function gates(): array
    {
        return ['REMINDER_EMAIL_WORKER_ENABLED' => (bool) config('otosanayide.workers.reminder_email'), 'EMAIL_DELIVERY_ENABLED' => (bool) config('otosanayide.reminder_email_delivery')];
    }
}
