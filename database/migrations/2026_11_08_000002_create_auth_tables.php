<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_accounts', function (Blueprint $t) {
            $t->cid('id')->primary();
            $t->cid('email', 254)->unique();
            $t->ms('email_verified_at')->nullable();
            $t->string('password_hash', 255)->charset('ascii')->collation('ascii_bin');
            $t->ms('password_changed_at');
            $t->ms('tokens_valid_after');
            $t->boolean('is_admin')->default(false);
            $t->boolean('is_account_erasure_operator')->default(false);
            $t->boolean('is_brand_finance')->default(false);
            $t->enum('status', ['active', 'disabled', 'erasing'])->charset('ascii')->collation('ascii_bin')->default('active');
            $t->unsignedInteger('version')->default(0);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('auth_sessions', function (Blueprint $t) {
            $t->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $t->cid('account_id');
            $t->char('family_id', 36)->charset('ascii')->collation('ascii_bin')->index();
            $t->sha256('refresh_token_hash')->unique();
            $t->enum('second_factor', ['totp', 'phone'])->charset('ascii')->collation('ascii_bin')->nullable();
            $t->ms('auth_time');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('last_used_at')->nullable();
            $t->ms('expires_at')->index();
            $t->ms('revoked_at')->nullable();
            $t->char('replaced_by', 36)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->index(['account_id', 'revoked_at']);
            $t->foreign('account_id')->references('id')->on('auth_accounts')->cascadeOnDelete();
        });

        // Sanctum tablosu: tokenable_id metin kimlik, oturuma bagli, milisaniye hassasiyetli.
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('tokenable_type', 32)->charset('ascii')->collation('ascii_bin');
            $t->cid('tokenable_id');
            $t->char('session_id', 36)->charset('ascii')->collation('ascii_bin')->index();
            $t->text('name');
            $t->sha256('token')->unique();
            $t->text('abilities')->nullable();
            $t->ms('last_used_at')->nullable();
            $t->ms('expires_at')->nullable()->index();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $t->index(['tokenable_type', 'tokenable_id']);
            $t->foreign('session_id')->references('id')->on('auth_sessions')->cascadeOnDelete();
        });

        Schema::create('auth_email_tokens', function (Blueprint $t) {
            $t->sha256('token_hash')->primary();
            $t->cid('account_id');
            $t->enum('purpose', ['verify_email', 'reset_password'])->charset('ascii')->collation('ascii_bin');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('expires_at')->index();
            $t->ms('consumed_at')->nullable();
            $t->index(['account_id', 'purpose', 'consumed_at']);
            $t->foreign('account_id')->references('id')->on('auth_accounts')->cascadeOnDelete();
        });

        Schema::create('auth_mfa_factors', function (Blueprint $t) {
            $t->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $t->cid('account_id');
            $t->enum('type', ['totp', 'phone'])->charset('ascii')->collation('ascii_bin');
            $t->text('secret_ciphertext')->nullable();
            $t->unsignedBigInteger('last_used_step')->nullable();
            $t->ms('verified_at')->nullable();
            $t->ms('disabled_at')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['account_id', 'disabled_at']);
            $t->foreign('account_id')->references('id')->on('auth_accounts')->cascadeOnDelete();
        });

        Schema::create('auth_mfa_challenges', function (Blueprint $t) {
            $t->sha256('token_hash')->primary();
            $t->cid('account_id');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('expires_at')->index();
            $t->ms('consumed_at')->nullable();
            $t->foreign('account_id')->references('id')->on('auth_accounts')->cascadeOnDelete();
        });

        Schema::create('auth_events', function (Blueprint $t) {
            $t->id();
            $t->cid('account_id')->nullable(); // Hesap silinse de olay kalir; FK yok.
            $t->string('event', 48)->charset('ascii')->collation('ascii_bin');
            $t->sha256('ip_hash')->nullable();
            $t->dateTime('occurred_at', 3)->useCurrent();
            $t->index(['account_id', 'occurred_at']);
            $t->index('occurred_at');
        });

        DB::statement("ALTER TABLE auth_accounts ADD CONSTRAINT chk_auth_accounts_uid CHECK (REGEXP_LIKE(id, '^[A-Za-z0-9_-]{1,128}$', 'c'))");
    }

    public function down(): void
    {
        foreach (['auth_events', 'auth_mfa_challenges', 'auth_mfa_factors', 'auth_email_tokens', 'personal_access_tokens', 'auth_sessions', 'auth_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
