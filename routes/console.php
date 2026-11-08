<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ops:cleanup')->hourly()->withoutOverlapping(55)->onOneServer();

// Yazicilar varsayilan kapali; acilinca once kuru calisma ciktisi incelenmeli.
if (config('otosanayide.workers.request_notifications')) {
    Schedule::command('notifications:deliver-requests --apply')->everyMinute()->withoutOverlapping(5)->onOneServer();
}
if (config('otosanayide.workers.garage_recycle_purge')) {
    Schedule::command('garage:purge-recycle --apply')->daily()->withoutOverlapping(120)->onOneServer();
}
