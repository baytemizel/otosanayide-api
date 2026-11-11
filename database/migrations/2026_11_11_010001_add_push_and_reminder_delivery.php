<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Yalniz ekleyici (API-A A2/A3): native push saglayicisi, hatirlatma dis kanal kuyrugu, zamanlanmis is defteri.
// Kaynak: packages/server/{push-devices,reminder-delivery,reminder-email,reminder-push,notification-outbound-page,scheduled-jobs}.ts
return new class extends Migration
{
    public function up(): void
    {
        $state = static fn (Blueprint $t, string $c, array $v) => $t->enum($c, $v)->charset('ascii')->collation('ascii_bin');

        // Mevcut satirlar FCM kaydidir; apns yalniz iOS dogrudan APNs jetonu.
        Schema::table('push_devices', function (Blueprint $t) use ($state) {
            $state($t, 'provider', ['fcm', 'apns'])->default('fcm')->after('platform');
            $state($t, 'apns_environment', ['production', 'sandbox'])->nullable()->after('provider');
        });

        // reminder_inbox olayinin kaynak baglari ve dis kanal kuyruk bayraklari (emailPending/pushPending).
        Schema::table('notifications', function (Blueprint $t) use ($state) {
            $t->cid('reminder_id', 180)->nullable()->after('request_version');
            $t->cid('vehicle_id', 100)->nullable()->after('reminder_id');
            $t->boolean('email_pending')->default(false);
            $t->boolean('push_pending')->default(false);
            $t->unsignedBigInteger('email_queue_closed_at_ms')->nullable();
            $t->unsignedBigInteger('push_queue_closed_at_ms')->nullable();
            $state($t, 'email_queue_outcome', ['processed', 'preference_changed', 'expired'])->nullable();
            $state($t, 'push_queue_outcome', ['processed', 'preference_changed', 'expired'])->nullable();
            $t->index(['email_pending', 'id']);
            $t->index(['push_pending', 'id']);
        });

        // notifications.emailDelivery / pushDelivery[deviceId] makbuzlari. E-posta icin device_id bos metindir.
        Schema::create('notification_deliveries', function (Blueprint $t) use ($state) {
            $t->cid('notification_id', 180);
            $state($t, 'channel', ['email', 'push']);
            $t->string('device_id', 36)->charset('ascii')->collation('ascii_bin')->default('');
            $state($t, 'state', ['prepared', 'dispatched', 'accepted', 'invalid_token', 'uncertain']);
            $t->sha256('source_digest');
            $t->sha256('idempotency_key');
            $t->string('message_id', 500)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedBigInteger('updated_at_ms');
            $t->primary(['notification_id', 'channel', 'device_id']);
        });

        // scheduledRuns/{job}: tek kayit, kendiliginden devralma yok; running/uncertain operator kurtarmasi ister.
        Schema::create('scheduled_runs', function (Blueprint $t) use ($state) {
            $t->string('job', 32)->charset('ascii')->collation('ascii_bin')->primary();
            $t->sha256('binding_digest');
            $t->char('run_id', 36)->charset('ascii')->collation('ascii_bin');
            $state($t, 'state', ['idle', 'running', 'uncertain']);
            $t->unsignedBigInteger('version');
            $t->string('cursor', 1200)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedBigInteger('updated_at_ms');
            $t->unsignedBigInteger('lease_until_ms');
            $t->unsignedBigInteger('completed_pages')->default(0);
            $t->unsignedBigInteger('completed_sweeps')->default(0);
            $t->json('last_counts')->nullable();
            $t->cid('recovered_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_runs');
        Schema::dropIfExists('notification_deliveries');
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropIndex(['email_pending', 'id']);
            $t->dropIndex(['push_pending', 'id']);
            $t->dropColumn(['reminder_id', 'vehicle_id', 'email_pending', 'push_pending', 'email_queue_closed_at_ms', 'push_queue_closed_at_ms', 'email_queue_outcome', 'push_queue_outcome']);
        });
        Schema::table('push_devices', function (Blueprint $t) {
            $t->dropColumn(['provider', 'apns_environment']);
        });
    }
};
