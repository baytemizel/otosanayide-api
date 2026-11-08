<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// claimVerifications/{auto}: admin claim-evidence kaniti; claims PATCH onayinda okunur.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_verifications', function (Blueprint $t) {
            $t->cid('id', 120)->primary();
            $t->cid('claim_id', 120);
            $t->cid('business_id', 120);
            $t->cid('user_id');
            $t->enum('status', ['verified'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('evidence_revision');
            $t->unsignedBigInteger('expires_at_ms');
            $t->cid('reviewed_by');
            $t->text('reason');
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index('claim_id');
            $t->foreign('claim_id')->references('id')->on('claims')->cascadeOnDelete();
        });

        Schema::create('claim_verification_evidence', function (Blueprint $t) {
            $t->cid('verification_id', 120);
            $t->unsignedTinyInteger('position');
            $t->char('evidence_id', 36)->charset('ascii')->collation('ascii_bin');
            $t->primary(['verification_id', 'position']);
            $t->foreign('verification_id')->references('id')->on('claim_verifications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_verification_evidence');
        Schema::dropIfExists('claim_verifications');
    }
};
