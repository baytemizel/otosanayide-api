<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// API-A 2. tur (endpoint talepleri). Yalniz ekleyici: yeni tablo, varsayilanli/nullable kolon, indeks.
return new class extends Migration
{
    public function up(): void
    {
        $ascii = static fn (Blueprint $t, string $c, int $n = 36) => $t->char($c, $n)->charset('ascii')->collation('ascii_bin');
        $state = static fn (Blueprint $t, string $c, array $v) => $t->enum($c, $v)->charset('ascii')->collation('ascii_bin');

        // web-03: e-posta degisimi. auth_email_tokens.purpose enum'u degistirilmez; ayri tablo.
        Schema::create('auth_email_changes', function (Blueprint $t) {
            $t->sha256('token_hash')->primary();
            $t->cid('account_id');
            $t->cid('new_email', 254);
            $t->unsignedBigInteger('expires_at_ms');
            $t->unsignedBigInteger('consumed_at_ms')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->index(['account_id', 'consumed_at_ms']);
        });

        // web-07 + portal-04: yapilandirilmis calisma saatleri (yayinda) ve bekleyen revizyon alani.
        Schema::table('businesses', function (Blueprint $t) {
            $t->json('opening_hours')->nullable();
        });
        Schema::table('business_changes', function (Blueprint $t) use ($state) {
            $state($t, 'opening_hours_action', ['keep', 'set', 'clear'])->default('keep');
            $t->json('opening_hours')->nullable();
        });

        // web-05/web-08: yorum ozeti arama projeksiyonunda; yorum yayim/geri cekme transaction'inda guncellenir.
        Schema::table('business_search', function (Blueprint $t) {
            $t->unsignedInteger('review_count')->default(0);
            $t->unsignedInteger('review_sum')->default(0);
            $t->double('review_score')->default(0);
            $t->index(['review_score', 'business_id']);
            $t->index(['review_count', 'business_id']);
        });

        // web-04: yanlis bilgi bildirimi (profili degistirmez; yonetici kuyrugu).
        Schema::create('business_reports', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $t->cid('business_id', 120);
            $t->cid('reporter_uid')->nullable();
            $t->sha256('reporter_ip_hash')->nullable();
            $ascii($t, 'operation_id');
            $state($t, 'reason', ['closed', 'moved', 'phone', 'address', 'location', 'hours', 'services', 'other']);
            $t->string('note', 1000)->default('');
            $state($t, 'status', ['submitted', 'resolved', 'dismissed']);
            $t->unsignedInteger('version')->default(1);
            $t->string('resolution', 1000)->nullable();
            $t->cid('reviewed_by')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->unique(['operation_id']);
            $t->index(['status', 'created_at_ms', 'id']);
            $t->index(['business_id', 'reporter_uid', 'status']);
        });

        // portal-05: teklif kalemleri. Tutarlar kurus (tam sayi); toplam sunucuda hesaplanir.
        Schema::table('requests', function (Blueprint $t) {
            $t->json('offer_lines')->nullable();
            $t->char('offer_currency', 3)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->string('offer_excluded', 500)->nullable();
            $t->boolean('offer_may_change')->nullable();
            // portal-03: musaitlikten secilen saat (istek); onay degildir.
            $t->unsignedBigInteger('preferred_starts_at_ms')->nullable();
        });

        // web-15 madde 2: talep ici ek bilgi yazismasi.
        Schema::create('request_messages', function (Blueprint $t) use ($ascii, $state) {
            $t->cid('request_id', 120);
            $ascii($t, 'id');
            $ascii($t, 'operation_id');
            $state($t, 'author', ['customer', 'business']);
            $t->cid('author_uid');
            $t->string('text', 1000);
            $t->unsignedBigInteger('created_at_ms');
            $t->primary(['request_id', 'id']);
            $t->unique(['request_id', 'operation_id']);
            $t->index(['request_id', 'created_at_ms']);
        });

        // portal-03: randevu kapasitesi, kapali gunler ve kapasite sayaclari.
        Schema::create('business_appointment_settings', function (Blueprint $t) use ($state) {
            $t->cid('business_id', 120)->primary();
            $t->unsignedSmallInteger('slot_minutes');
            $t->unsignedTinyInteger('capacity');
            $state($t, 'approval', ['manual', 'auto_offer']);
            $t->boolean('accepting');
            $t->unsignedInteger('version');
            $t->cid('updated_by');
            $t->unsignedBigInteger('updated_at_ms');
        });
        Schema::create('business_closures', function (Blueprint $t) use ($ascii, $state) {
            $t->cid('business_id', 120);
            $ascii($t, 'id');
            $t->date('from_date');
            $t->date('to_date');
            $state($t, 'reason', ['holiday', 'leave', 'other']);
            $t->string('note', 200)->default('');
            $t->unsignedBigInteger('created_at_ms');
            $t->primary(['business_id', 'id']);
            $t->index(['business_id', 'from_date']);
        });
        Schema::create('appointment_bookings', function (Blueprint $t) {
            $t->cid('business_id', 120);
            $t->cid('request_id', 120);
            $t->unsignedBigInteger('starts_at_ms');
            $t->primary(['business_id', 'request_id']);
            $t->index(['business_id', 'starts_at_ms']);
        });

        // portal-01: ekip davetleri ve denetim kaydi. Rol modeli business_memberships (owner/staff) aynen.
        Schema::create('business_invitations', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $t->cid('business_id', 120);
            $t->cid('email', 254);
            $t->sha256('token_hash')->unique();
            $state($t, 'status', ['pending', 'accepted', 'cancelled', 'expired']);
            $t->cid('invited_by');
            $t->cid('accepted_by')->nullable();
            $t->unsignedBigInteger('expires_at_ms');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->index(['business_id', 'status']);
        });
        Schema::create('business_team_audit', function (Blueprint $t) use ($ascii) {
            $t->cid('business_id', 120);
            $ascii($t, 'id');
            $t->string('action', 32)->charset('ascii')->collation('ascii_bin');
            $t->cid('actor_uid');
            $t->string('subject', 300)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->unsignedBigInteger('created_at_ms');
            $t->primary(['business_id', 'id']);
            $t->index(['business_id', 'created_at_ms']);
        });

        // web-09: bildirim tercihleri ve urun duyurusu onay kaydi (gonderim yok).
        Schema::create('user_preferences', function (Blueprint $t) use ($state) {
            $t->cid('user_id')->primary();
            $t->unsignedInteger('version');
            $t->boolean('request_updates_push')->default(false);
            $t->boolean('request_updates_email')->default(false);
            $t->boolean('reminder_email')->default(true);
            $t->boolean('product_news')->default(false);
            $t->unsignedBigInteger('product_news_consent_at_ms')->nullable();
            $state($t, 'product_news_consent_source', ['web', 'portal', 'ios', 'android'])->nullable();
            $t->string('product_news_text_version', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedBigInteger('updated_at_ms');
        });
        Schema::create('user_consent_events', function (Blueprint $t) use ($ascii, $state) {
            $t->cid('user_id');
            $ascii($t, 'id');
            $state($t, 'kind', ['product_news']);
            $t->boolean('granted');
            $state($t, 'source', ['web', 'portal', 'ios', 'android']);
            $t->string('text_version', 32)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('created_at_ms');
            $t->primary(['user_id', 'id']);
        });

        // web-12: kapsam bekleme listesi (yalniz oturumlu, dogrulanmis hesap).
        Schema::create('coverage_waitlist', function (Blueprint $t) {
            $t->cid('user_id');
            $t->string('city', 80);
            $t->unsignedBigInteger('created_at_ms');
            $t->primary(['user_id', 'city']);
            $t->index('city');
        });

        // web-13: favori listeleri. user_favorites (Kaydedilenler) degismez.
        Schema::create('favorite_lists', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $t->cid('user_id');
            $ascii($t, 'operation_id');
            $t->string('name', 60);
            $state($t, 'kind', ['list', 'comparison']);
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->unique(['user_id', 'operation_id']);
            $t->index(['user_id', 'created_at_ms']);
        });
        Schema::create('favorite_list_items', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'list_id');
            $t->cid('business_id', 120);
            $t->unsignedSmallInteger('position');
            $t->primary(['list_id', 'business_id']);
        });

        // web-10: veri kopyasi talebi. Otomatik paket uretimi yok; operator isler.
        Schema::create('privacy_exports', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $t->cid('owner_uid');
            $state($t, 'status', ['submitted', 'in_progress', 'delivered', 'rejected', 'cancelled']);
            $t->unsignedInteger('version');
            $t->string('reason', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->cid('handled_by')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->index(['owner_uid', 'created_at_ms']);
            $t->index(['status', 'created_at_ms', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['auth_email_changes', 'privacy_exports', 'favorite_list_items', 'favorite_lists', 'coverage_waitlist', 'user_consent_events', 'user_preferences', 'business_team_audit', 'business_invitations', 'appointment_bookings', 'business_closures', 'business_appointment_settings', 'request_messages', 'business_reports'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('requests', fn (Blueprint $t) => $t->dropColumn(['offer_lines', 'offer_currency', 'offer_excluded', 'offer_may_change', 'preferred_starts_at_ms']));
        Schema::table('business_search', function (Blueprint $t) {
            $t->dropIndex(['review_score', 'business_id']);
            $t->dropIndex(['review_count', 'business_id']);
            $t->dropColumn(['review_count', 'review_sum', 'review_score']);
        });
        Schema::table('business_changes', fn (Blueprint $t) => $t->dropColumn(['opening_hours_action', 'opening_hours']));
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('opening_hours'));
    }
};
