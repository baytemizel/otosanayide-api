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

// API-A A3: hatirlatma isleri. Her calisma bir sayfadir; scheduled_runs defteri running/uncertain kalirsa durur.
if (config('otosanayide.workers.reminder_inbox')) {
    Schedule::command('reminders:deliver-inbox --apply')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
}
if (config('otosanayide.workers.reminder_email') && config('otosanayide.reminder_email_delivery')) {
    Schedule::command('reminders:deliver-email --apply')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
}
if (config('otosanayide.workers.reminder_push') && config('otosanayide.flags.push_delivery')) {
    Schedule::command('reminders:deliver-push --apply')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
}

// --- F7 (API-B) başlangıç ---
// Marka gorseli saklama temizligi: bayrak kapaliyken zamanlanmaz; acilmadan once kuru calisma incelenmeli.
if (config('ads.workers.brand_media_cleanup')) {
    Schedule::command('brand:media-cleanup --apply')->hourly()->withoutOverlapping(55)->onOneServer();
}
// --- F7 (API-B) bitiş ---
