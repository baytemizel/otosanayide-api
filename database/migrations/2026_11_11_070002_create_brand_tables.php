<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F7 marka (banner) reklamlari: brandCampaigns, tarife/kapasite, envanter, rezervasyon, siparis,
// odeme baglama/durum/makbuz/vaka, kesin kabul, iptal/iade, uzlastirma incelemesi, medya ve olcum.
return new class extends Migration
{
    public function up(): void
    {
        $uuid = static fn (Blueprint $t, string $c) => $t->char($c, 36)->charset('ascii')->collation('ascii_bin');
        $ascii = static fn (Blueprint $t, string $c, int $n = 40) => $t->string($c, $n)->charset('ascii')->collation('ascii_bin');
        $placement = static fn (Blueprint $t, string $c = 'placement') => $t->enum($c, ['home_banner', 'category_banner'])->charset('ascii')->collation('ascii_bin');

        Schema::create('brand_campaigns', function (Blueprint $t) use ($uuid, $ascii, $placement) {
            $uuid($t, 'id')->primary();
            $t->string('brand', 200);
            $t->string('headline', 200);
            $t->string('description', 800);
            $t->string('url', 500)->charset('utf8mb4')->collation('utf8mb4_bin');
            $placement($t);
            $t->string('city', 100);
            $ascii($t, 'category', 100);
            $t->char('start_date', 10)->charset('ascii')->collation('ascii_bin');
            $t->char('end_date', 10)->charset('ascii')->collation('ascii_bin');
            $t->enum('status', ['draft', 'pending', 'approved', 'rejected', 'paused'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('version');
            $t->text('note');
            $t->cid('last_editor_id', 200);
            $uuid($t, 'asset_id')->nullable();
            $t->sha256('asset_sha256')->nullable();
            $t->unsignedSmallInteger('asset_width')->nullable();
            $t->unsignedSmallInteger('asset_height')->nullable();
            $t->unsignedInteger('asset_bytes')->nullable();
            $t->unsignedSmallInteger('media_attempts')->default(0);
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
        });

        Schema::create('brand_campaign_history', function (Blueprint $t) use ($uuid, $ascii) {
            $t->id();
            $uuid($t, 'campaign_id');
            $ascii($t, 'action', 20);
            $t->cid('actor_id', 200);
            $t->json('snapshot');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['campaign_id', 'id']);
        });

        Schema::create('brand_media_views', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'campaign_id');
            $t->sha256('viewer_key');
            $t->cid('user_id', 200);
            $uuid($t, 'image_id');
            $t->sha256('sha256');
            $t->unsignedBigInteger('version');
            $t->unsignedBigInteger('viewed_at_ms');
            $t->primary(['campaign_id', 'viewer_key']);
        });

        Schema::create('brand_media_uploads', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'id')->primary();
            $uuid($t, 'campaign_id');
            $t->enum('status', ['uploading', 'failed', 'attached', 'purging', 'purged'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('created_at_ms');
            $t->cid('actor_id', 200)->nullable();
            $uuid($t, 'upload_nonce');
            $t->sha256('sha256')->nullable();
            $t->unsignedInteger('bytes')->nullable();
            $t->unsignedBigInteger('orphan_observed_at_ms')->nullable();
            $t->boolean('retention_hold')->nullable();
            $t->index(['campaign_id']);
            $t->index(['status', 'id']);
        });

        Schema::create('brand_tariffs', function (Blueprint $t) use ($ascii, $placement) {
            $t->sha256('id')->primary();
            $placement($t);
            $t->string('city', 100);
            $ascii($t, 'category', 100);
            $t->boolean('enabled');
            $t->unsignedBigInteger('daily_minor');
            $t->unsignedSmallInteger('tax_basis_points');
            $t->unsignedBigInteger('version');
            $t->dateTime('updated_at', 3)->useCurrent();
        });

        Schema::create('brand_tariff_history', function (Blueprint $t) {
            $t->id();
            $t->sha256('tariff_id');
            $t->unsignedBigInteger('version');
            $t->json('value');
            $t->text('reason');
            $t->cid('actor_id', 200);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['tariff_id', 'version']);
        });

        Schema::create('brand_capacities', function (Blueprint $t) use ($placement) {
            $placement($t)->primary();
            $t->boolean('enabled');
            $t->unsignedTinyInteger('capacity');
            $t->unsignedBigInteger('version');
        });

        Schema::create('brand_capacity_history', function (Blueprint $t) use ($placement) {
            $t->id();
            $placement($t);
            $t->json('value');
            $t->text('reason');
            $t->cid('actor_id', 200);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index('placement');
        });

        Schema::create('brand_inventory_days', function (Blueprint $t) use ($placement) {
            $placement($t);
            $t->char('date', 10)->charset('ascii')->collation('ascii_bin');
            $t->json('holds');
            $t->primary(['placement', 'date']);
        });

        Schema::create('brand_reservation_anchors', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'campaign_id')->primary();
            $uuid($t, 'reservation_id');
            $t->unsignedBigInteger('expires_at_ms');
        });

        Schema::create('brand_reservations', function (Blueprint $t) use ($uuid, $ascii, $placement) {
            $uuid($t, 'id')->primary();
            $uuid($t, 'campaign_id');
            $t->unsignedBigInteger('campaign_version');
            $t->unsignedBigInteger('tariff_version');
            $t->unsignedBigInteger('capacity_version');
            $placement($t);
            $placement($t, 'market_placement');
            $t->string('market_city', 100);
            $ascii($t, 'market_category', 100);
            $t->char('start_date', 10)->charset('ascii')->collation('ascii_bin');
            $t->char('end_date', 10)->charset('ascii')->collation('ascii_bin');
            $t->unsignedSmallInteger('days');
            $t->json('dates');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('daily_minor');
            $t->unsignedSmallInteger('tax_basis_points');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->enum('status', ['held', 'released', 'committed'])->charset('ascii')->collation('ascii_bin');
            $t->cid('actor_id', 200);
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('expires_at_ms');
            $t->unsignedBigInteger('committed_until_ms')->nullable();
            $t->unsignedBigInteger('released_at_ms')->nullable();
            $ascii($t, 'release_reason')->nullable();
            $t->index('campaign_id');
        });

        Schema::create('brand_reservation_history', function (Blueprint $t) use ($uuid, $ascii) {
            $t->id();
            $uuid($t, 'reservation_id');
            $ascii($t, 'action', 20);
            $t->cid('actor_id', 200);
            $t->unsignedBigInteger('at_ms');
            $t->index('reservation_id');
        });

        Schema::create('brand_orders', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'reservation_id')->primary();
            $uuid($t, 'campaign_id');
            $t->unsignedBigInteger('campaign_version');
            $t->unsignedBigInteger('tariff_version');
            $t->unsignedBigInteger('capacity_version');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('expires_at_ms');
            $t->enum('status', ['prepared'])->charset('ascii')->collation('ascii_bin');
        });

        Schema::create('brand_order_history', function (Blueprint $t) use ($uuid, $ascii) {
            $t->id();
            $uuid($t, 'reservation_id');
            $ascii($t, 'action', 20);
            $t->cid('actor_id', 200)->nullable();
            $t->sha256('receipt_id')->nullable();
            $ascii($t, 'outcome', 20)->nullable();
            $ascii($t, 'reason')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->index('reservation_id');
        });

        Schema::create('brand_payment_bindings', function (Blueprint $t) use ($uuid, $ascii) {
            $uuid($t, 'reservation_id')->primary();
            $ascii($t, 'provider');
            $t->string('merchant_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->unsignedBigInteger('created_at_ms');
        });

        Schema::create('brand_payment_states', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'reservation_id')->primary();
            $t->enum('status', ['pending', 'failed', 'verified', 'needs_review'])->charset('ascii')->collation('ascii_bin');
            $t->sha256('primary_receipt_id')->nullable();
            $t->unsignedBigInteger('checked_at_ms');
        });

        Schema::create('brand_payment_receipts', function (Blueprint $t) use ($uuid, $ascii) {
            $t->sha256('id')->primary();
            $uuid($t, 'record_id');
            $ascii($t, 'provider');
            $t->string('merchant_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->string('payment_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('captured_at_ms')->nullable();
            $t->enum('outcome', ['verified', 'needs_review'])->charset('ascii')->collation('ascii_bin');
            $ascii($t, 'reason');
            $t->unsignedBigInteger('checked_at_ms');
            $t->index('record_id');
        });

        Schema::create('brand_payment_cases', function (Blueprint $t) use ($uuid, $ascii) {
            $t->sha256('id')->primary();
            $uuid($t, 'record_id');
            $t->sha256('receipt_id');
            $ascii($t, 'reason');
            $t->enum('status', ['open', 'reviewing', 'refund_required', 'closed'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('version')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms')->nullable();
        });

        Schema::create('brand_payment_case_history', function (Blueprint $t) use ($ascii) {
            $t->sha256('case_id');
            $t->unsignedBigInteger('version');
            $ascii($t, 'status', 20);
            $ascii($t, 'previous_status', 20);
            $t->text('note');
            $t->cid('actor_id', 200);
            $t->unsignedBigInteger('updated_at_ms');
            $t->primary(['case_id', 'version']);
        });

        Schema::create('brand_acceptances', function (Blueprint $t) use ($uuid, $placement) {
            $uuid($t, 'reservation_id')->primary();
            $uuid($t, 'campaign_id');
            $t->unsignedBigInteger('campaign_version');
            $t->sha256('receipt_id');
            $placement($t);
            $t->json('dates');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('total_minor');
            $t->enum('status', ['accepted'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('accepted_at_ms');
            $t->unsignedBigInteger('committed_until_ms');
        });

        Schema::create('brand_acceptance_history', function (Blueprint $t) use ($uuid, $ascii) {
            $t->id();
            $uuid($t, 'reservation_id');
            $ascii($t, 'action', 20);
            $t->cid('actor_id', 200);
            $t->unsignedBigInteger('created_at_ms');
            $t->index('reservation_id');
        });

        Schema::create('brand_cancellations', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'reservation_id')->primary();
            $uuid($t, 'campaign_id');
            $t->enum('status', ['pending', 'approved', 'rejected'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('version');
            $t->cid('requester_id', 200);
            $t->text('reason');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('decided_at_ms')->nullable();
            $t->cid('reviewer_id', 200)->default('');
            $t->text('note');
            $t->index(['status', 'reservation_id']);
        });

        Schema::create('brand_cancellation_history', function (Blueprint $t) use ($uuid, $ascii) {
            $uuid($t, 'id')->primary();
            $uuid($t, 'reservation_id');
            $ascii($t, 'action', 20);
            $t->cid('actor_id', 200)->nullable();
            $t->text('note')->nullable();
            $t->sha256('receipt_id')->nullable();
            $ascii($t, 'outcome', 20)->nullable();
            $ascii($t, 'reason')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->index(['reservation_id', 'created_at_ms', 'id']);
        });

        Schema::create('brand_refund_states', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'reservation_id')->primary();
            $t->enum('status', ['requires_review', 'needs_review', 'refunded'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('checked_at_ms');
            $t->sha256('receipt_id')->nullable();
            $t->index(['status', 'reservation_id']);
        });

        Schema::create('brand_refund_receipts', function (Blueprint $t) use ($uuid, $ascii) {
            $t->sha256('id')->primary();
            $uuid($t, 'reservation_id');
            $ascii($t, 'provider');
            $t->string('merchant_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->string('refund_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->string('payment_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->enum('status', ['pending', 'failed', 'succeeded'])->charset('ascii')->collation('ascii_bin');
            $t->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('refunded_at_ms')->nullable();
            $t->enum('outcome', ['needs_review', 'refunded'])->charset('ascii')->collation('ascii_bin');
            $ascii($t, 'reason');
            $t->unsignedBigInteger('checked_at_ms');
            $t->index('reservation_id');
        });

        Schema::create('brand_refund_reviews', function (Blueprint $t) use ($uuid) {
            $t->sha256('id')->primary();
            $uuid($t, 'reservation_id');
            $t->sha256('receipt_id');
            $t->unsignedInteger('version');
            $t->enum('status', ['reviewing', 'closed'])->charset('ascii')->collation('ascii_bin');
            $t->text('note');
            $t->cid('actor_id', 200);
            $t->boolean('acknowledged');
            $t->unsignedBigInteger('updated_at_ms');
        });

        Schema::create('brand_refund_review_history', function (Blueprint $t) use ($uuid, $ascii) {
            $t->sha256('review_id');
            $t->unsignedInteger('version');
            $uuid($t, 'reservation_id');
            $t->sha256('receipt_id');
            $ascii($t, 'status', 20);
            $ascii($t, 'previous_status', 20);
            $t->text('note');
            $t->cid('actor_id', 200);
            $t->boolean('acknowledged');
            $t->unsignedBigInteger('updated_at_ms');
            $t->primary(['review_id', 'version']);
        });

        Schema::create('brand_metric_events', function (Blueprint $t) {
            $t->sha256('id')->primary();
            $t->boolean('view')->default(false);
            $t->boolean('click')->default(false);
            $t->dateTime('expires_at', 3);
            $t->index('expires_at');
        });

        Schema::create('brand_campaign_metrics', function (Blueprint $t) use ($uuid) {
            $uuid($t, 'campaign_id');
            $t->char('date', 10)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('views')->default(0);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->primary(['campaign_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['brand_campaign_metrics', 'brand_metric_events', 'brand_refund_review_history', 'brand_refund_reviews', 'brand_refund_receipts', 'brand_refund_states', 'brand_cancellation_history', 'brand_cancellations', 'brand_acceptance_history', 'brand_acceptances', 'brand_payment_case_history', 'brand_payment_cases', 'brand_payment_receipts', 'brand_payment_states', 'brand_payment_bindings', 'brand_order_history', 'brand_orders', 'brand_reservation_history', 'brand_reservations', 'brand_reservation_anchors', 'brand_inventory_days', 'brand_capacity_history', 'brand_capacities', 'brand_tariff_history', 'brand_tariffs', 'brand_media_uploads', 'brand_media_views', 'brand_campaign_history', 'brand_campaigns'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
