<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
 * 2. tur testleri icin surec ici SQLite bellek veritabani. Uretim MySQL'ine baglanilmaz (DB_PORT=1 korunur).
 * Tablolar yalniz kodun dokundugu kolonlarla elle kurulur. MySQL kabulu DEGILDIR.
 */
function r2Db(?PDO $pdo = null): void
{
    config(['database.connections.r2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false], 'database.default' => 'r2', 'otosanayide.portal_web_origin' => 'https://portal.otosanayide.test']);
    DB::purge('r2');
    if ($pdo !== null) {
        DB::connection('r2')->setPdo($pdo);
    }
    $s = Schema::connection('r2');
    $str = static fn (Blueprint $t, array $cols, bool $nullable = true) => array_map(fn ($c) => $nullable ? $t->string($c)->nullable() : $t->string($c), $cols);
    $s->create('auth_accounts', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('email')->unique(), $t->string('email_verified_at')->nullable(), $t->string('password_hash'), $t->string('password_changed_at'), $t->string('tokens_valid_after'), $t->boolean('is_admin')->default(false), $t->boolean('is_account_erasure_operator')->default(false), $t->boolean('is_brand_finance')->default(false), $t->string('status')->default('active')]);
    $s->create('auth_sessions', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('account_id'), $t->string('family_id'), $t->string('refresh_token_hash')->unique(), $t->string('second_factor')->nullable(), $t->string('auth_time'), $t->string('expires_at'), $t->string('revoked_at')->nullable(), $t->string('replaced_by')->nullable(), $t->string('last_used_at')->nullable()]);
    $s->create('personal_access_tokens', fn (Blueprint $t) => [$t->id(), $t->string('tokenable_type'), $t->string('tokenable_id'), $t->string('session_id'), $t->text('name'), $t->string('token')->unique(), $t->text('abilities')->nullable(), $t->string('last_used_at')->nullable(), $t->string('expires_at')->nullable(), $t->string('created_at')->nullable(), $t->string('updated_at')->nullable()]);
    $s->create('auth_mfa_factors', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('account_id'), $t->string('type'), $t->text('secret_ciphertext')->nullable(), $t->integer('last_used_step')->nullable(), $t->string('verified_at')->nullable(), $t->string('disabled_at')->nullable()]);
    $s->create('auth_email_tokens', fn (Blueprint $t) => [$t->string('token_hash')->primary(), $t->string('account_id'), $t->string('purpose'), $t->string('expires_at'), $t->string('consumed_at')->nullable()]);
    $s->create('auth_email_changes', fn (Blueprint $t) => [$t->string('token_hash')->primary(), $t->string('account_id'), $t->string('new_email'), $t->bigInteger('expires_at_ms'), $t->bigInteger('consumed_at_ms')->nullable(), $t->bigInteger('created_at_ms')]);
    $s->create('auth_events', fn (Blueprint $t) => [$t->id(), $t->string('account_id')->nullable(), $t->string('event'), $t->string('ip_hash')->nullable()]);
    $s->create('account_fences', fn (Blueprint $t) => [$t->string('owner_uid')->primary(), $t->string('state')]);
    $s->create('businesses', function (Blueprint $t) use ($str) {
        $t->string('id')->primary();
        $str($t, ['name', 'city', 'district', 'address', 'phone', 'contact_email', 'website', 'hours', 'description', 'coordinate_status', 'ownership', 'owner_uid', 'business_cover_photo_id', 'import_source_id', 'photo_attempt_window']);
        $t->text('opening_hours')->nullable();
        $t->float('latitude')->nullable();
        $t->float('longitude')->nullable();
        foreach (['published', 'sample', 'owner_ready', 'feature_quotes', 'feature_appointments'] as $c) {
            $t->boolean($c)->default(false);
        }
        $t->boolean('card_payment')->nullable();
        $t->boolean('wc')->nullable();
        foreach (['publication_version', 'profile_version', 'activation_version', 'review_count', 'review_rating_sum', 'photo_attempts'] as $c) {
            $t->integer($c)->default(0);
        }
    });
    $s->create('business_values', fn (Blueprint $t) => [$t->string('business_id'), $t->string('kind'), $t->integer('position'), $t->string('value')]);
    $s->create('business_public_photos', fn (Blueprint $t) => [$t->string('business_id'), $t->integer('position'), $t->string('photo_id')]);
    $s->create('business_photo_slots', fn (Blueprint $t) => [$t->string('business_id'), $t->integer('position')->default(0), $t->string('photo_id')]);
    $s->create('business_memberships', fn (Blueprint $t) => [$t->string('user_id'), $t->string('business_id'), $t->string('role'), $t->boolean('active')->default(true), $t->integer('version')->default(0), $t->string('created_at')->nullable(), $t->primary(['user_id', 'business_id'])]);
    $s->create('user_preferences', fn (Blueprint $t) => [$t->string('user_id')->primary(), $t->integer('version'), $t->boolean('request_updates_push')->default(false), $t->boolean('request_updates_email')->default(false), $t->boolean('reminder_email')->default(true), $t->boolean('product_news')->default(false), $t->bigInteger('product_news_consent_at_ms')->nullable(), $t->string('product_news_consent_source')->nullable(), $t->string('product_news_text_version')->nullable(), $t->bigInteger('updated_at_ms')]);
    $s->create('user_consent_events', fn (Blueprint $t) => [$t->string('user_id'), $t->string('id'), $t->string('kind'), $t->boolean('granted'), $t->string('source'), $t->string('text_version'), $t->bigInteger('created_at_ms')]);
    $s->create('coverage_waitlist', fn (Blueprint $t) => [$t->string('user_id'), $t->string('city'), $t->bigInteger('created_at_ms'), $t->primary(['user_id', 'city'])]);
    $s->create('favorite_lists', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('user_id'), $t->string('operation_id'), $t->string('name'), $t->string('kind'), $t->integer('version'), $t->bigInteger('created_at_ms'), $t->bigInteger('updated_at_ms'), $t->unique(['user_id', 'operation_id'])]);
    $s->create('favorite_list_items', fn (Blueprint $t) => [$t->string('list_id'), $t->string('business_id'), $t->integer('position'), $t->primary(['list_id', 'business_id'])]);
    $s->create('privacy_exports', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('owner_uid'), $t->string('status'), $t->integer('version'), $t->string('reason')->nullable(), $t->string('handled_by')->nullable(), $t->bigInteger('created_at_ms'), $t->bigInteger('updated_at_ms')]);
    $s->create('business_reports', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('business_id'), $t->string('reporter_uid')->nullable(), $t->string('reporter_ip_hash')->nullable(), $t->string('operation_id')->unique(), $t->string('reason'), $t->string('note')->default(''), $t->string('status'), $t->integer('version')->default(1), $t->string('resolution')->nullable(), $t->string('reviewed_by')->nullable(), $t->bigInteger('created_at_ms'), $t->bigInteger('updated_at_ms')]);
    $s->create('business_invitations', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('business_id'), $t->string('email'), $t->string('token_hash')->unique(), $t->string('status'), $t->string('invited_by'), $t->string('accepted_by')->nullable(), $t->bigInteger('expires_at_ms'), $t->bigInteger('created_at_ms'), $t->bigInteger('updated_at_ms')]);
    $s->create('business_team_audit', fn (Blueprint $t) => [$t->string('business_id'), $t->string('id'), $t->string('action'), $t->string('actor_uid'), $t->string('subject'), $t->bigInteger('created_at_ms')]);
    $s->create('requests', function (Blueprint $t) use ($str) {
        $t->string('id')->primary();
        $str($t, ['business_id', 'business_name', 'user_id', 'kind', 'service', 'vehicle', 'message', 'preferred_date', 'status', 'offer_text', 'offer_starts_at', 'resolution', 'offer_currency', 'offer_excluded', 'updated_at']);
        $t->integer('version');
        $t->float('offer_price')->nullable();
        $t->bigInteger('offer_expires_at_ms')->nullable();
        $t->text('offer_lines')->nullable();
        $t->boolean('offer_may_change')->nullable();
        $t->bigInteger('preferred_starts_at_ms')->nullable();
        $t->string('created_at')->default('2026-11-11 08:00:00.000');
    });
    $s->create('request_history', fn (Blueprint $t) => [$t->string('request_id'), $t->string('id'), $t->integer('version'), $t->string('action'), $t->string('text')->nullable(), $t->float('price')->nullable(), $t->string('starts_at')->nullable(), $t->string('actor_id'), $t->string('role'), $t->string('from_status'), $t->string('to_status')]);
    $s->create('request_messages', fn (Blueprint $t) => [$t->string('request_id'), $t->string('id'), $t->string('operation_id'), $t->string('author'), $t->string('author_uid'), $t->string('text'), $t->bigInteger('created_at_ms'), $t->primary(['request_id', 'id']), $t->unique(['request_id', 'operation_id'])]);
    $s->create('notification_jobs', fn (Blueprint $t) => [$t->string('id')->primary(), $t->string('request_id'), $t->string('business_id'), $t->string('customer_id'), $t->integer('request_version'), $t->string('request_status'), $t->bigInteger('created_at_ms'), $t->string('state'), $t->integer('attempts'), $t->bigInteger('next_attempt_at_ms')->nullable()]);
    $s->create('appointment_slots', fn (Blueprint $t) => [$t->string('business_id'), $t->bigInteger('starts_at_ms'), $t->string('request_id'), $t->string('starts_at'), $t->primary(['business_id', 'starts_at_ms'])]);
    $s->create('appointment_bookings', fn (Blueprint $t) => [$t->string('business_id'), $t->string('request_id'), $t->bigInteger('starts_at_ms'), $t->primary(['business_id', 'request_id'])]);
    $s->create('business_appointment_settings', fn (Blueprint $t) => [$t->string('business_id')->primary(), $t->integer('slot_minutes'), $t->integer('capacity'), $t->string('approval'), $t->boolean('accepting'), $t->integer('version'), $t->string('updated_by'), $t->bigInteger('updated_at_ms')]);
    $s->create('business_closures', fn (Blueprint $t) => [$t->string('business_id'), $t->string('id'), $t->string('from_date'), $t->string('to_date'), $t->string('reason'), $t->string('note')->default(''), $t->bigInteger('created_at_ms')]);
    $s->create('public_reviews', fn (Blueprint $t) => [$t->string('business_id'), $t->string('review_id'), $t->integer('rating'), $t->text('reply_text')->nullable()]);
    $s->create('review_replies', fn (Blueprint $t) => [$t->string('review_id')->primary(), $t->string('status')]);
    $s->create('review_photo_refs', fn (Blueprint $t) => [$t->string('review_id'), $t->string('kind')->default('public'), $t->integer('position')->default(0), $t->string('photo_id')->default('')]);
    $s->create('user_favorites', fn (Blueprint $t) => [$t->string('user_id'), $t->string('business_id')]);
    $s->create('reviews', function (Blueprint $t) use ($str) {
        $t->string('id')->primary();
        $str($t, ['business_id', 'user_id', 'resolved', 'wc', 'text', 'status', 'reason', 'reviewed_by', 'updated_at']);
        foreach (['rating', 'quality', 'payment', 'cleanliness', 'wc_cleanliness'] as $c) {
            $t->integer($c)->nullable();
        }
        foreach (['version', 'submission_version', 'photo_attempts'] as $c) {
            $t->integer($c)->default(1);
        }
        $t->boolean('moderation_hold')->default(false);
        $t->string('created_at')->default('2026-11-01 00:00:00.000');
    });
    foreach (['claims' => 'submitted', 'review_photos' => 'pending', 'business_changes' => 'pending', 'review_cases' => 'open', 'business_photos' => 'pending', 'privacy_requests' => 'submitted'] as $table => $_) {
        $s->create($table, fn (Blueprint $t) => [$t->string('id')->nullable(), $t->string('status')]);
    }
}

/** Hesap + oturum + erisim jetonu. @return array<string,string> sunucu basliklari */
function r2User(string $uid, bool $verified = true, bool $admin = false, ?string $email = null): array
{
    $now = gmdate('Y-m-d H:i:s').'.000';
    DB::table('auth_accounts')->insert(['id' => $uid, 'email' => $email ?? $uid.'@example.com', 'email_verified_at' => $verified ? $now : null, 'password_hash' => Hash::make('correct-horse-1'), 'password_changed_at' => '2026-01-01 00:00:00.000', 'tokens_valid_after' => '2026-01-01 00:00:00.000', 'is_admin' => $admin]);
    DB::table('auth_sessions')->insert(['id' => 's-'.$uid, 'account_id' => $uid, 'family_id' => 'f-'.$uid, 'refresh_token_hash' => hash('sha256', 'r-'.$uid), 'auth_time' => $now, 'expires_at' => '2099-01-01 00:00:00.000']);
    $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => 'account', 'tokenable_id' => $uid, 'session_id' => 's-'.$uid, 'name' => 'access', 'token' => hash('sha256', 'p-'.$uid), 'abilities' => '["*"]', 'expires_at' => '2099-01-01 00:00:00.000', 'created_at' => $now, 'updated_at' => $now]);

    return ['HTTP_AUTHORIZATION' => 'Bearer '.$id.'|p-'.$uid, 'HTTP_ACCEPT_LANGUAGE' => ''];
}

/** Yayindaki, sahipligi dogrulanmis, randevu/teklif acik isletme. */
function r2Business(string $id, ?string $owner = null, array $over = []): void
{
    DB::table('businesses')->insert($over + ['id' => $id, 'name' => 'Sentetik Servis '.$id, 'city' => 'İstanbul', 'district' => 'Kadıköy', 'address' => 'Sentetik açık adres 1, Kadıköy', 'phone' => '', 'website' => '', 'hours' => '', 'description' => '', 'coordinate_status' => 'verified', 'latitude' => 40.99, 'longitude' => 29.03, 'published' => true, 'ownership' => $owner === null ? 'unclaimed' : 'verified', 'owner_uid' => $owner, 'owner_ready' => $owner !== null, 'feature_quotes' => $owner !== null, 'feature_appointments' => $owner !== null]);
    DB::table('business_values')->insert(['business_id' => $id, 'kind' => 'category', 'position' => 0, 'value' => 'mechanical']);
    if ($owner !== null) {
        DB::table('business_memberships')->insert(['user_id' => $owner, 'business_id' => $id, 'role' => 'owner', 'active' => true, 'version' => 1]);
    }
}

function r2(string $method, string $uri, array $server = [], ?string $body = null)
{
    return test()->call($method, $uri, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], $body);
}
