<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $t) {
            $t->cid('id', 120)->primary();
            $t->string('name', 1000);
            $t->string('city', 100)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->string('district', 500);
            $t->text('address');
            $t->text('phone');
            $t->string('contact_email', 254)->nullable();
            $t->text('website');
            $t->string('hours', 300)->default('');
            $t->text('description');
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->enum('coordinate_status', ['verified', 'unverified'])->charset('ascii')->collation('ascii_bin')->default('unverified');
            $t->boolean('published')->default(false);
            $t->boolean('sample')->default(false);
            $t->enum('ownership', ['unclaimed', 'pending', 'verified'])->charset('ascii')->collation('ascii_bin')->default('unclaimed');
            $t->cid('owner_uid')->nullable()->index();
            $t->boolean('owner_ready')->default(false);
            $t->boolean('feature_quotes')->default(false);
            $t->boolean('feature_appointments')->default(false);
            $t->boolean('card_payment')->nullable();
            $t->boolean('wc')->nullable();
            $t->char('business_cover_photo_id', 36)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedInteger('profile_version')->default(0);
            $t->unsignedInteger('publication_version')->default(0);
            $t->unsignedInteger('activation_version')->default(0);
            $t->char('import_source_id', 24)->charset('ascii')->collation('ascii_bin')->nullable()->unique();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $t->index(['published', 'city']);
        });

        // Dizi alanlari (categories/services/brands) sirasi korunarak.
        Schema::create('business_values', function (Blueprint $t) {
            $t->cid('business_id', 120);
            $t->enum('kind', ['category', 'service', 'brand'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedSmallInteger('position');
            $t->cid('value', 120);
            $t->primary(['business_id', 'kind', 'position']);
            $t->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        Schema::create('business_public_photos', function (Blueprint $t) {
            $t->cid('business_id', 120);
            $t->unsignedTinyInteger('position');
            $t->char('photo_id', 36)->charset('ascii')->collation('ascii_bin');
            $t->primary(['business_id', 'position']);
            $t->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        Schema::create('business_history', function (Blueprint $t) {
            $t->cid('business_id', 120);
            $t->cid('id', 120);
            $t->string('kind', 64)->charset('ascii')->collation('ascii_bin');
            $t->json('details')->nullable(); // Yazara gore degisen denetim yuku; sorgulanmaz.
            $t->dateTime('created_at', 3)->useCurrent();
            $t->primary(['business_id', 'id']);
            $t->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        Schema::create('catalogue_sources', function (Blueprint $t) {
            $t->char('id', 24)->charset('ascii')->collation('ascii_bin')->primary();
            $t->cid('business_id', 120)->unique();
            $t->char('candidate_id', 24)->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('source_row');
            $t->sha256('source_hash');
            $t->string('name', 1000);
            $t->string('city', 100);
            $t->string('district', 500);
            $t->text('address');
            $t->text('phone')->nullable();
            $t->string('contact_email', 254)->nullable();
            $t->text('website')->nullable();
            $t->json('raw_source'); // Opak kaynak satiri.
            $t->json('extra')->nullable(); // passthrough alanlari; opak.
            $t->dateTime('imported_at', 3)->useCurrent();
            $t->foreign('business_id')->references('id')->on('businesses');
        });

        Schema::create('catalogue_source_values', function (Blueprint $t) {
            $t->char('source_id', 24)->charset('ascii')->collation('ascii_bin');
            $t->enum('kind', ['category', 'specialty', 'flag'])->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('position');
            $t->text('value');
            $t->primary(['source_id', 'kind', 'position']);
            $t->foreign('source_id')->references('id')->on('catalogue_sources')->cascadeOnDelete();
        });

        // Zorunlu: varsayilan InnoDB durak kelimeleri 'a', 'i', 'in' gibi parcalari icerir;
        // ngram bunlari iceren ikilileri atar ve Turkce arama bozulur (0003_search.sql).
        DB::statement('SET SESSION innodb_ft_enable_stopword = OFF');
        DB::statement(<<<'SQL'
            CREATE TABLE business_search (
              business_id VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              name VARCHAR(255) NOT NULL,
              city VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              district VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
              district_norm VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
              card TINYINT(1) NOT NULL DEFAULT 0,
              search_text TEXT NOT NULL,
              latitude DOUBLE NOT NULL,
              longitude DOUBLE NOT NULL,
              location POINT NOT NULL SRID 4326,
              fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
              PRIMARY KEY (business_id),
              KEY idx_business_search_area (city, district_norm, business_id),
              FULLTEXT KEY ft_business_search_text (search_text) WITH PARSER ngram,
              SPATIAL KEY sp_business_search_location (location),
              CONSTRAINT fk_business_search_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_tr_0900_ai_ci
            SQL);
        DB::statement('SET SESSION innodb_ft_enable_stopword = ON');

        Schema::create('business_search_facets', function (Blueprint $t) {
            $t->cid('business_id', 120);
            $t->enum('facet', ['category', 'service', 'brand'])->charset('ascii')->collation('ascii_bin');
            $t->cid('value', 128);
            $t->primary(['business_id', 'facet', 'value']);
            $t->index(['facet', 'value', 'business_id']);
            $t->foreign('business_id')->references('business_id')->on('business_search')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['business_search_facets', 'business_search', 'catalogue_source_values', 'catalogue_sources', 'business_history', 'business_public_photos', 'business_values', 'businesses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
