<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// media-operation-ledger.ts + account-external-operations.ts:
// open/uncertain (media) ve prepared/dispatched/uncertain (dis cagri) kayitlari ayni kaynak icin UNIQUE ile tekildir.
return new class extends Migration
{
    public function up(): void
    {
        $ascii = static fn (Blueprint $t, string $c, int $n = 36) => $t->char($c, $n)->charset('ascii')->collation('ascii_bin');
        $state = static fn (Blueprint $t, string $c, array $v) => $t->enum($c, $v)->charset('ascii')->collation('ascii_bin');

        Schema::create('account_media_barriers', function (Blueprint $t) {
            $t->cid('owner_uid')->primary();
            $t->unsignedInteger('active')->default(0);
            $t->unsignedInteger('uncertain')->default(0);
            $t->unsignedBigInteger('sequence')->default(0);
        });

        Schema::create('account_media_operations', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $t->cid('actor_uid')->nullable();
            $state($t, 'resource_kind', ['review', 'review_parent', 'evidence', 'claim_parent', 'business', 'business_parent']);
            $t->cid('resource_id', 120);
            $t->cid('resource_claim_id', 120)->nullable();
            $t->string('path', 255)->charset('ascii')->collation('ascii_bin');
            $state($t, 'method', ['save', 'delete']);
            $t->json('binding'); // Hazirlik anindaki kaynak ozeti; yalniz ozetle karsilastirilir.
            $state($t, 'state', ['open', 'uncertain', 'closed']);
            $t->boolean('dispatched')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->cid('resolved_by')->nullable();
            $t->sha256('evidence_digest')->nullable();
            $t->string('active_path', 255)->charset('ascii')->collation('ascii_bin')->nullable()->storedAs("IF(`state` IN ('open','uncertain'), `path`, NULL)")->unique();
            $t->index(['path', 'state']);
        });

        Schema::create('account_media_operation_subjects', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'operation_id');
            $t->cid('uid');
            $t->primary(['operation_id', 'uid']);
            $t->index('uid');
            $t->foreign('operation_id')->references('id')->on('account_media_operations')->cascadeOnDelete();
        });

        Schema::create('account_external_barriers', function (Blueprint $t) {
            $t->cid('owner_uid')->primary();
            $t->unsignedBigInteger('active')->default(0);
            $t->unsignedBigInteger('uncertain')->default(0);
            $t->unsignedBigInteger('sequence')->default(0);
        });

        Schema::create('account_external_operations', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $state($t, 'kind', ['ai', 'email', 'push', 'payments', 'places']);
            $t->sha256('binding_digest');
            $t->sha256('resource_key');
            $t->sha256('source_digest');
            $state($t, 'state', ['prepared', 'dispatched', 'uncertain', 'settled']);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->cid('resolved_by')->nullable();
            $t->sha256('evidence_digest')->nullable();
            $ascii($t, 'active_resource_key', 64)->nullable()->storedAs("IF(`state` <> 'settled', `resource_key`, NULL)")->unique();
            $t->index(['resource_key', 'state']);
        });

        Schema::create('account_external_operation_subjects', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'operation_id');
            $t->cid('uid');
            $t->primary(['operation_id', 'uid']);
            $t->index('uid');
            $t->foreign('operation_id')->references('id')->on('account_external_operations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['account_external_operation_subjects', 'account_external_operations', 'account_external_barriers', 'account_media_operation_subjects', 'account_media_operations', 'account_media_barriers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
