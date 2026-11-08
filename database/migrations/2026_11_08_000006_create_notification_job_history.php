<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_job_history', function (Blueprint $t) {
            $t->cid('job_id', 180);
            $t->char('id', 36)->charset('ascii')->collation('ascii_bin');
            $t->string('action', 32)->charset('ascii')->collation('ascii_bin');
            $t->string('reason', 1000);
            $t->cid('actor_id');
            $t->unsignedBigInteger('created_at_ms');
            $t->primary(['job_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_job_history');
    }
};
