<?php

namespace App\Console\Commands;

use App\Domain\Ops\RequestNotificationWorker;
use Illuminate\Console\Command;

final class DeliverRequestNotifications extends Command
{
    protected $signature = 'notifications:deliver-requests {--apply : Uygulama ici kutu kayitlarini yazar}';

    protected $description = 'Bekleyen talep bildirimlerini uygulama ici kutuya yazar; --apply olmadan yalniz sayar.';

    public function handle(RequestNotificationWorker $worker): int
    {
        $this->line((string) json_encode($worker->run((bool) $this->option('apply'))));

        return self::SUCCESS;
    }
}
