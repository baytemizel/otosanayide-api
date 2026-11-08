<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hesap silme cerceveleri: yazarlar ayni transaction'da okur (account-write-barrier.ts).
        Schema::create('account_fences', function (Blueprint $t) {
            $t->cid('owner_uid')->primary();
            $t->char('job_id', 36)->charset('ascii')->collation('ascii_bin');
            $t->char('epoch', 36)->charset('ascii')->collation('ascii_bin');
            $t->enum('state', ['deleting', 'deleted'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('created_at_ms');
        });

        // businessMemberships/{uid}_{businessId}
        Schema::create('business_memberships', function (Blueprint $t) {
            $t->cid('user_id');
            $t->cid('business_id', 120);
            $t->enum('role', ['owner', 'staff'])->charset('ascii')->collation('ascii_bin');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $t->primary(['user_id', 'business_id']);
            $t->index(['business_id', 'active']);
            $t->foreign('business_id')->references('id')->on('businesses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_memberships');
        Schema::dropIfExists('account_fences');
    }
};
