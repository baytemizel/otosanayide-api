<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $ascii = fn (Blueprint $t, string $c, int $n = 36) => $t->char($c, $n)->charset('ascii')->collation('ascii_bin');

        Schema::create('user_vehicles', function (Blueprint $t) {
            $t->cid('user_id');
            $t->cid('id', 100);
            $t->string('brand', 50);
            $t->string('model', 80);
            $t->smallInteger('year');
            $t->string('fuel', 16);
            $t->integer('mileage');
            $t->date('inspection_due_date')->nullable();
            $t->char('photo_id', 36)->nullable();
            $t->boolean('archived')->default(false);
            $t->string('created_at_iso', 40)->nullable();
            $t->string('updated_at_iso', 40)->nullable();
            $t->primary(['user_id', 'id']);
        });

        Schema::create('user_maintenance', function (Blueprint $t) {
            $t->cid('user_id');
            $t->cid('id', 100);
            $t->cid('vehicle_id', 100);
            $t->string('title', 120);
            $t->date('date');
            $t->integer('mileage');
            $t->double('amount');
            $t->string('note', 1000)->default('');
            $t->json('attachment_ids')->nullable(); // Ozel medya kapali: yalniz bos dizi/yokluk ayrimi.
            $t->string('created_at_iso', 40)->nullable();
            $t->string('updated_at_iso', 40)->nullable();
            $t->primary(['user_id', 'id']);
            $t->index(['user_id', 'vehicle_id']);
        });

        Schema::create('user_reminders', function (Blueprint $t) use ($ascii) {
            $t->cid('user_id');
            $t->cid('id', 180);
            $t->cid('vehicle_id', 100);
            $t->string('title', 100);
            $t->boolean('completed')->nullable();
            $t->date('due_date')->nullable();
            $t->integer('due_km')->nullable();
            $t->boolean('channel_push')->default(false);
            $t->boolean('channel_email')->default(false);
            $t->boolean('channels_set')->default(false); // channels alani hic verilmemis / bos dizi ayrimi.
            $t->tinyInteger('advance_days')->nullable();
            $t->unsignedBigInteger('next_check_at_ms')->nullable()->index();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('updated_at')->nullable();
            $t->primary(['user_id', 'id']);
        });

        Schema::create('user_favorites', function (Blueprint $t) {
            $t->cid('user_id');
            $t->cid('business_id', 120); // FK yok: silinen isletme favoride null olarak gorunur.
            $t->dateTime('created_at', 3)->useCurrent();
            $t->primary(['user_id', 'business_id']);
        });

        // Butun anlik goruntu tek birim okunur/yazilir ve her okumada semadan gecer: opak belge.
        Schema::create('garage_backups', function (Blueprint $t) use ($ascii) {
            $t->cid('user_id')->primary();
            $t->unsignedInteger('revision');
            $t->string('updated_at_iso', 40);
            $ascii($t, 'operation_id');
            $t->json('garage');
        });

        Schema::create('garage_state', function (Blueprint $t) use ($ascii) {
            $t->cid('user_id')->primary();
            $ascii($t, 'migration_id');
            $t->unsignedInteger('migration_revision');
            $t->sha256('migration_digest');
            $t->string('activated_at_iso', 40);
            $t->sha256('last_request_digest')->nullable();
        });

        Schema::create('garage_recycle', function (Blueprint $t) use ($ascii) {
            $t->cid('user_id')->primary();
            $t->enum('state', ['trashed', 'restored', 'purged'])->charset('ascii')->collation('ascii_bin');
            $ascii($t, 'operation_id');
            $t->unsignedInteger('revision');
            $t->string('changed_at_iso', 40);
            $t->string('purge_after_iso', 40)->nullable();
            $t->json('backup')->nullable(); // Yalniz trashed durumunda; opak anlik goruntu.
            $t->index(['state', 'purge_after_iso']);
        });

        Schema::create('privacy_requests', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'id');
            $t->primary('id');
            $t->cid('owner_uid')->index();
            $t->enum('scope', ['garage', 'account'])->charset('ascii')->collation('ascii_bin');
            $t->enum('status', ['submitted', 'reviewing', 'needs_confirmation', 'cancelled'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->string('reason', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $ascii($t, 'last_operation_id');
            $t->string('last_action', 32)->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('last_expected_version');
            $t->cid('last_actor_uid');
            $t->index(['status', 'created_at_ms', 'owner_uid', 'id']);
        });

        Schema::create('privacy_state', function (Blueprint $t) use ($ascii) {
            $t->cid('owner_uid')->primary();
            $ascii($t, 'request_id');
        });

        Schema::create('privacy_request_history', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'request_id');
            $ascii($t, 'operation_id');
            $t->unsignedInteger('version');
            $t->string('action', 32)->charset('ascii')->collation('ascii_bin');
            $t->cid('actor_uid');
            $t->unsignedBigInteger('at_ms');
            $t->string('status', 32)->charset('ascii')->collation('ascii_bin');
            $t->string('reason', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->primary(['request_id', 'operation_id']);
        });

        // pushTokens + pushTokenClaims: ayni jeton iki cihaza baglanamaz, UNIQUE ile.
        Schema::create('push_devices', function (Blueprint $t) use ($ascii) {
            $t->cid('user_id');
            $ascii($t, 'device_id');
            $ascii($t, 'generation');
            $t->string('app_id', 128)->charset('ascii')->collation('ascii_bin');
            $t->enum('platform', ['web', 'android', 'ios'])->charset('ascii')->collation('ascii_bin');
            $t->enum('locale', ['tr', 'en'])->charset('ascii')->collation('ascii_bin');
            $t->boolean('enabled');
            $t->unsignedBigInteger('version');
            $t->unsignedBigInteger('updated_at_ms');
            $t->unsignedBigInteger('refresh_after_ms');
            $t->text('token')->nullable();
            $t->sha256('token_hash')->nullable()->unique();
            $t->primary(['user_id', 'device_id']);
        });

        Schema::create('requests', function (Blueprint $t) {
            $t->cid('id', 120)->primary();
            $t->cid('business_id', 120);
            $t->string('business_name', 1000);
            $t->cid('user_id');
            $t->enum('kind', ['quotes', 'appointments'])->charset('ascii')->collation('ascii_bin');
            $t->string('service', 120);
            $t->string('vehicle', 120);
            $t->string('message', 2000);
            $t->date('preferred_date')->nullable();
            $t->string('status', 16)->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('version');
            $t->string('offer_text', 2000)->nullable();
            $t->double('offer_price')->nullable();
            $t->string('offer_starts_at', 40)->nullable();
            $t->unsignedBigInteger('offer_expires_at_ms')->nullable();
            $t->string('resolution', 1000)->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('updated_at')->nullable();
            $t->index(['business_id', 'id']);
            $t->index(['user_id', 'id']);
        });

        Schema::create('request_history', function (Blueprint $t) use ($ascii) {
            $t->cid('request_id', 120);
            $ascii($t, 'id');
            $t->unsignedInteger('version');
            $t->string('action', 16)->charset('ascii')->collation('ascii_bin');
            $t->string('text', 2000)->nullable();
            $t->double('price')->nullable();
            $t->string('starts_at', 40)->nullable();
            $t->cid('actor_id');
            $t->enum('role', ['customer', 'business'])->charset('ascii')->collation('ascii_bin');
            $t->string('from_status', 16)->charset('ascii')->collation('ascii_bin');
            $t->string('to_status', 16)->charset('ascii')->collation('ascii_bin');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->primary(['request_id', 'id']);
            $t->foreign('request_id')->references('id')->on('requests');
        });

        // appointmentSlots/{businessId}_{ms}: ayni saate iki randevu PK ile engellenir.
        Schema::create('appointment_slots', function (Blueprint $t) {
            $t->cid('business_id', 120);
            $t->unsignedBigInteger('starts_at_ms');
            $t->cid('request_id', 120);
            $t->string('starts_at', 40);
            $t->primary(['business_id', 'starts_at_ms']);
        });

        Schema::create('notification_jobs', function (Blueprint $t) {
            $t->cid('id', 180)->primary();
            $t->cid('request_id', 120);
            $t->cid('business_id', 120);
            $t->cid('customer_id');
            $t->unsignedInteger('request_version');
            $t->string('request_status', 16)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('created_at_ms');
            $t->string('state', 16)->charset('ascii')->collation('ascii_bin');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedBigInteger('next_attempt_at_ms')->nullable();
            $t->char('lease_id', 36)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedBigInteger('lease_until_ms')->nullable();
            $t->string('channel', 16)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedBigInteger('delivered_at_ms')->nullable();
            $t->string('last_error_code', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedBigInteger('updated_at_ms')->nullable();
            $t->index(['next_attempt_at_ms', 'id']);
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->cid('id', 180)->primary();
            $t->string('audience_key', 140)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->cid('business_id', 120)->nullable();
            $t->cid('request_id', 120)->nullable();
            $t->string('request_status', 16)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedInteger('request_version')->nullable();
            $t->string('kind', 16)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->string('vehicle_label', 200)->nullable();
            $t->string('title', 100)->nullable();
            $t->string('phase', 16)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->string('due_date', 10)->nullable();
            $t->integer('due_km')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('delivered_at_ms')->nullable();
            $t->index(['audience_key', 'id']);
        });

        Schema::create('notification_reads', function (Blueprint $t) {
            $t->cid('notification_id', 180);
            $t->cid('user_id');
            $t->boolean('read');
            $t->unsignedBigInteger('updated_at_ms');
            $t->primary(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['notification_reads', 'notifications', 'notification_jobs', 'appointment_slots', 'request_history', 'requests', 'push_devices', 'privacy_request_history', 'privacy_state', 'privacy_requests', 'garage_recycle', 'garage_state', 'garage_backups', 'user_favorites', 'user_reminders', 'user_maintenance', 'user_vehicles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
