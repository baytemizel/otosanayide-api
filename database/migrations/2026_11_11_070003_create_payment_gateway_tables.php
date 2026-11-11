<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F7 normalized gateway v1: kalici checkout oturumu ve webhook gelen kutusu (replay/lease).
// Kart verisi, saglayici jetonu veya ham webhook govdesi saklanmaz.
return new class extends Migration
{
    public function up(): void
    {
        $ascii = static fn (Blueprint $t, string $c, int $n = 40) => $t->string($c, $n)->charset('ascii')->collation('ascii_bin');

        Schema::create('payment_checkout_sessions', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'id', 50)->primary();
            $t->unsignedTinyInteger('schema');
            $t->enum('kind', ['business', 'brand'])->charset('ascii')->collation('ascii_bin');
            $t->char('order_id', 36)->charset('ascii')->collation('ascii_bin');
            $t->cid('user_id');
            $t->json('subjects');
            $t->sha256('source_digest');
            $ascii($t, 'provider');
            $t->string('merchant_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->sha256('idempotency_key');
            $t->enum('status', ['prepared', 'dispatched', 'ready', 'uncertain', 'closed'])->charset('ascii')->collation('ascii_bin');
            $t->string('session_id', 200)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->string('session_url', 4096)->charset('utf8mb4')->collation('utf8mb4_bin')->nullable();
            $t->unsignedBigInteger('session_expires_at_ms')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedBigInteger('updated_at_ms');
            $t->unsignedBigInteger('version');
            $t->index(['status', 'id']);
        });

        Schema::create('payment_gateway_inbox', function (Blueprint $t) use ($ascii) {
            $t->sha256('id')->primary();
            $t->unsignedTinyInteger('schema');
            $ascii($t, 'provider');
            $t->string('merchant_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $ascii($t, 'event_id', 128);
            $t->sha256('event_digest');
            $t->enum('kind', ['business', 'brand'])->charset('ascii')->collation('ascii_bin');
            $t->char('order_id', 36)->charset('ascii')->collation('ascii_bin');
            $t->string('payment_id', 200)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->json('subjects');
            $t->enum('status', ['processing', 'applied', 'review'])->charset('ascii')->collation('ascii_bin');
            $t->char('lease_id', 36)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('lease_until_ms');
            $t->unsignedTinyInteger('attempts');
            $ascii($t, 'result')->nullable();
            $t->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_inbox');
        Schema::dropIfExists('payment_checkout_sessions');
    }
};
