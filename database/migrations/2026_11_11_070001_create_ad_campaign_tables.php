<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F7 isletme reklamlari: campaigns, adTariffs, adInventory, adReservations, adOrders, odeme makbuzu/vakasi, olcum.
// Tutarlar TRY kurus (minor) tamsayidir. Gun basina tutma listesi (holds) en fazla 20 elemanli JSON'dur;
// kapasite gun satiri FOR UPDATE ile kilitlenerek denetlenir.
return new class extends Migration
{
    public function up(): void
    {
        $uuid = static fn (Blueprint $t, string $c) => $t->char($c, 36)->charset('ascii')->collation('ascii_bin');

        Schema::create('ad_campaigns', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'id')->primary();
            $t->cid('business_id', 120);
            $t->cid('owner_uid')->nullable();
            $t->enum('placement', ['search', 'category', 'home'])->charset('ascii')->collation('ascii_bin');
            $t->string('city', 100);
            $t->string('category', 100)->charset('ascii')->collation('ascii_bin');
            $t->unsignedTinyInteger('days');
            $t->char('start_date', 10)->charset('ascii')->collation('ascii_bin');
            $t->string('headline', 200);
            $t->string('description', 600);
            $t->string('business_name', 200)->default('');
            $t->unsignedBigInteger('approved_profile_version')->nullable();
            $t->enum('status', ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'scheduled'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('version');
            $t->text('reason');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->index(['business_id', 'id']);
            $t->index(['status', 'id']);
        });

        Schema::create('ad_campaign_history', function (Blueprint $t) use ($uuid) {
            $t->id();
            $uuid($t, 'campaign_id');
            $t->string('action', 20)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('version');
            $t->cid('actor_id');
            $t->text('reason');
            $t->json('snapshot');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['campaign_id', 'id']);
            $t->foreign('campaign_id')->references('id')->on('ad_campaigns')->cascadeOnDelete();
        });

        Schema::create('ad_tariffs', function (Blueprint $t) {
            $t->sha256('id')->primary();
            $t->enum('placement', ['search', 'category', 'home'])->charset('ascii')->collation('ascii_bin');
            $t->string('city', 100);
            $t->string('category', 100)->charset('ascii')->collation('ascii_bin');
            $t->boolean('enabled');
            $t->unsignedTinyInteger('capacity');
            $t->unsignedBigInteger('seven_days_minor');
            $t->unsignedBigInteger('thirty_days_minor');
            $t->unsignedSmallInteger('tax_basis_points');
            $t->unsignedBigInteger('version');
            $t->dateTime('updated_at', 3)->useCurrent();
        });

        Schema::create('ad_tariff_history', function (Blueprint $t) {
            $t->id();
            $t->sha256('tariff_id');
            $t->unsignedBigInteger('version');
            $t->json('value');
            $t->text('reason');
            $t->cid('actor_id');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['tariff_id', 'version']);
        });

        Schema::create('ad_inventory_days', function (Blueprint $t) {
            $t->sha256('market_id');
            $t->char('date', 10)->charset('ascii')->collation('ascii_bin');
            $t->json('holds');
            $t->primary(['market_id', 'date']);
        });

        Schema::create('ad_campaign_reservations', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'campaign_id')->primary();
            $uuid($t, 'reservation_id');
            $t->unsignedBigInteger('expires_at_ms');
        });

        Schema::create('ad_reservations', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'id')->primary();
            $t->cid('user_id');
            $uuid($t, 'campaign_id');
            $t->cid('business_id', 120);
            $t->sha256('market_id');
            $t->unsignedBigInteger('campaign_version');
            $t->unsignedBigInteger('tariff_version');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->json('dates');
            $t->enum('status', ['held', 'released', 'paid'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('expires_at_ms');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('released_at_ms')->nullable();
            $uuid($t, 'order_id')->nullable();
            $t->index('campaign_id');
        });

        Schema::create('ad_reservation_history', function (Blueprint $t) use ($uuid) {
            $t->id();
            $uuid($t, 'reservation_id');
            $t->string('kind', 20)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('created_at_ms');
            $t->index('reservation_id');
        });

        Schema::create('ad_orders', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'id')->primary();
            $t->cid('user_id');
            $uuid($t, 'reservation_id')->unique();
            $uuid($t, 'campaign_id');
            $t->cid('business_id', 120);
            $t->unsignedBigInteger('campaign_version');
            $t->string('provider', 40)->charset('ascii')->collation('ascii_bin');
            $t->string('merchant_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->enum('status', ['pending', 'failed', 'succeeded', 'paid', 'needs_review'])->charset('ascii')->collation('ascii_bin');
            $t->string('reason', 40)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->sha256('receipt_id')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('checked_at_ms')->nullable();
            $t->unsignedBigInteger('paid_at_ms')->nullable();
        });

        Schema::create('ad_payment_receipts', function (Blueprint $t) use ($uuid) {
            $t->sha256('id')->primary();
            $uuid($t, 'order_id');
            $t->string('provider', 40)->charset('ascii')->collation('ascii_bin');
            $t->string('payment_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('amount_minor');
            $t->enum('outcome', ['paid', 'needs_review'])->charset('ascii')->collation('ascii_bin');
            $t->string('reason', 40)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('captured_at_ms')->nullable();
            $t->unsignedBigInteger('checked_at_ms');
            $t->index('order_id');
        });

        Schema::create('ad_payment_cases', function (Blueprint $t) use ($uuid) {
            $t->sha256('id')->primary();
            $uuid($t, 'order_id');
            $t->sha256('receipt_id');
            $t->string('reason', 40)->charset('ascii')->collation('ascii_bin');
            $t->enum('status', ['open', 'reviewing', 'refund_required', 'closed'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('version')->default(0);
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms')->nullable();
        });

        Schema::create('ad_payment_case_history', function (Blueprint $t) {
            $t->id();
            $t->sha256('case_id');
            $t->enum('status', ['open', 'reviewing', 'refund_required', 'closed'])->charset('ascii')->collation('ascii_bin');
            $t->string('previous_status', 20)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('version');
            $t->text('note');
            $t->cid('actor_id');
            $t->unsignedBigInteger('updated_at_ms');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index('case_id');
        });

        Schema::create('ad_metric_events', function (Blueprint $t) {
            $t->sha256('id')->primary();
            $t->boolean('view')->default(false);
            $t->boolean('click')->default(false);
            $t->dateTime('expires_at', 3);
            $t->index('expires_at');
        });

        Schema::create('ad_campaign_metrics', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'campaign_id');
            $t->char('date', 10)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('views')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->primary(['campaign_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['ad_campaign_metrics', 'ad_metric_events', 'ad_payment_case_history', 'ad_payment_cases', 'ad_payment_receipts', 'ad_orders', 'ad_reservation_history', 'ad_reservations', 'ad_campaign_reservations', 'ad_inventory_days', 'ad_tariff_history', 'ad_tariffs', 'ad_campaign_history', 'ad_campaigns'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
