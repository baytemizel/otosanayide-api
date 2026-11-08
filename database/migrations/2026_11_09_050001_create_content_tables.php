<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F5: yorum, yanit, basvuru, sahiplik, profil degisikligi, aktivasyon ve foto tablolari.
// Sutunlar packages/server yazimlarindan ve Zod semalarindan turetildi; admin uclarinin yazdigi alanlar dahil.
return new class extends Migration
{
    public function up(): void
    {
        $ascii = static fn (Blueprint $t, string $c, int $n = 36) => $t->char($c, $n)->charset('ascii')->collation('ascii_bin');
        $state = static fn (Blueprint $t, string $c, array $v) => $t->enum($c, $v)->charset('ascii')->collation('ascii_bin');

        // automatic.{local,ai}: yazilan alanlar; signals/categories sorgulanmayan denetim listesi.
        $automatic = static function (Blueprint $t) use ($ascii, $state): void {
            $t->string('local_policy_version', 16)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->boolean('local_flagged')->nullable();
            $t->boolean('local_obfuscation')->nullable();
            $t->json('local_signals')->nullable();
            $state($t, 'ai_status', ['not_requested', 'running', 'checked', 'unavailable'])->default('not_requested');
            $t->boolean('ai_flagged')->nullable();
            $t->json('ai_categories')->nullable();
            $t->string('ai_model', 120)->nullable();
            $ascii($t, 'ai_run_id')->nullable();
            $t->unsignedBigInteger('ai_requested_at_ms')->nullable();
            $t->ms('ai_checked_at')->nullable();
        };

        // Her alt koleksiyon history belgesi: kimligi rastgele, yazara gore degisen alanlar opak `details`.
        $history = static function (string $table, string $parent, int $length = 120, ?string $references = null, string $referenceColumn = 'id') use ($ascii): void {
            Schema::create($table, function (Blueprint $t) use ($parent, $length, $references, $referenceColumn, $ascii) {
                if ($length === 36) {
                    $ascii($t, $parent);
                } else {
                    $t->cid($parent, $length);
                }
                $ascii($t, 'id');
                $t->string('kind', 64)->charset('ascii')->collation('ascii_bin');
                $t->unsignedInteger('version')->nullable();
                $t->cid('actor_id')->nullable();
                $t->json('details')->nullable();
                $t->dateTime('created_at', 3)->useCurrent();
                $t->primary([$parent, 'id']);
                if ($references !== null) {
                    $t->foreign($parent)->references($referenceColumn)->on($references)->cascadeOnDelete();
                }
            });
        };

        Schema::table('businesses', function (Blueprint $t) use ($ascii) {
            // reviewCount / reviewRatingSum: yalniz reviewTotals() ile degisir.
            $t->unsignedInteger('review_count')->default(0);
            $t->unsignedBigInteger('review_rating_sum')->default(0);
            // photoAttempts / photoAttemptWindow: 30 gunluk kayan yukleme kotasi.
            $t->unsignedInteger('photo_attempts')->default(0);
            $t->unsignedBigInteger('photo_attempt_window')->nullable();
            // businessIdentityKeys/{sha256} kilit belgesi yerine UNIQUE (admin claim-targets).
            $ascii($t, 'identity_key', 64)->nullable()->unique();
            $t->cid('created_by')->nullable();
            $t->string('duplicate_review_status', 32)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->string('source', 32)->charset('ascii')->collation('ascii_bin')->nullable();
        });

        Schema::table('business_memberships', function (Blueprint $t) {
            // Admin claim onayinin yazdigi alanlar.
            $t->cid('claim_id', 120)->nullable();
            $t->cid('granted_by')->nullable();
        });

        // businessPhotoSlots: yuklemede ayrilan, sirali referanslar.
        Schema::create('business_photo_slots', function (Blueprint $t) use ($ascii) {
            $t->cid('business_id', 120);
            $t->unsignedTinyInteger('position');
            $ascii($t, 'photo_id');
            $t->primary(['business_id', 'position']);
            $t->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        Schema::create('reviews', function (Blueprint $t) use ($state, $automatic) {
            $t->cid('id', 120)->primary();
            $t->cid('business_id', 120);
            $t->cid('user_id');
            $t->unsignedTinyInteger('rating');
            $t->unsignedTinyInteger('quality')->nullable();
            $t->unsignedTinyInteger('payment')->nullable();
            $t->unsignedTinyInteger('cleanliness')->nullable();
            $t->unsignedTinyInteger('wc_cleanliness')->nullable();
            $state($t, 'resolved', ['yes', 'partial', 'no', 'unknown']);
            $state($t, 'wc', ['yes', 'no', 'unknown']);
            $t->text('text');
            $state($t, 'status', ['pending', 'approved', 'rejected', 'hidden', 'withdrawn']);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('submission_version')->default(1);
            $t->boolean('moderation_hold')->default(false);
            $t->text('reason')->nullable();
            $t->cid('reviewed_by')->nullable();
            $t->ms('reviewed_at')->nullable();
            $t->ms('withdrawn_at')->nullable();
            $t->unsignedInteger('photo_attempts')->default(0);
            $automatic($t);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('updated_at')->nullable();
            $t->index(['status', 'id']);
            $t->index(['business_id', 'status']);
            $t->index('user_id');
            $t->foreign('business_id')->references('id')->on('businesses');
        });

        // reviews.photoSlots / reviews.publicPhotoIds
        Schema::create('review_photo_refs', function (Blueprint $t) use ($ascii, $state) {
            $t->cid('review_id', 120);
            $state($t, 'kind', ['slot', 'public']);
            $t->unsignedTinyInteger('position');
            $ascii($t, 'photo_id');
            $t->primary(['review_id', 'kind', 'position']);
            $t->foreign('review_id')->references('id')->on('reviews')->cascadeOnDelete();
        });

        $history('review_history', 'review_id', 120, 'reviews');

        // businesses/{id}/publicReviews/{reviewId}
        Schema::create('public_reviews', function (Blueprint $t) use ($state) {
            $t->cid('business_id', 120);
            $t->cid('review_id', 120);
            $t->unsignedTinyInteger('rating');
            $t->unsignedTinyInteger('quality')->nullable();
            $t->unsignedTinyInteger('payment')->nullable();
            $t->unsignedTinyInteger('cleanliness')->nullable();
            $t->unsignedTinyInteger('wc_cleanliness')->nullable();
            $state($t, 'resolved', ['yes', 'partial', 'no', 'unknown']);
            $state($t, 'wc', ['yes', 'no', 'unknown']);
            $t->text('text');
            $t->text('reply_text')->nullable();
            $t->dateTime('published_at', 3)->useCurrent();
            $t->primary(['business_id', 'review_id']);
            $t->index('review_id');
            $t->foreign('business_id')->references('id')->on('businesses');
        });

        Schema::create('public_review_photos', function (Blueprint $t) use ($ascii) {
            $t->cid('business_id', 120);
            $t->cid('review_id', 120);
            $t->unsignedTinyInteger('position');
            $ascii($t, 'photo_id');
            $t->primary(['business_id', 'review_id', 'position']);
            $t->foreign(['business_id', 'review_id'])->references(['business_id', 'review_id'])->on('public_reviews')->cascadeOnDelete();
        });

        // reviewReplies/{reviewId}
        Schema::create('review_replies', function (Blueprint $t) use ($state, $automatic) {
            $t->cid('review_id', 120)->primary();
            $t->cid('business_id', 120);
            $t->cid('user_id');
            $t->text('text');
            $state($t, 'status', ['pending', 'approved', 'rejected', 'hidden']);
            $t->unsignedInteger('version');
            $t->text('reason')->nullable();
            $t->cid('reviewed_by')->nullable();
            $t->ms('reviewed_at')->nullable();
            $automatic($t);
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('updated_at')->nullable();
            $t->index(['status', 'review_id']);
            $t->index('user_id');
        });

        $history('review_reply_history', 'review_id', 120, 'review_replies', 'review_id');

        // reviewCases/{sha256([uid,reviewId,kind,version])}: ana yorum silinse de `superseded` kapatilabilir, FK yok.
        Schema::create('review_cases', function (Blueprint $t) use ($state) {
            $t->cid('id', 120)->primary();
            $t->cid('review_id', 120);
            $t->cid('business_id', 120);
            $t->cid('user_id');
            $state($t, 'kind', ['report', 'appeal']);
            $t->text('text');
            $t->unsignedInteger('review_version');
            $state($t, 'status', ['open', 'closed']);
            $state($t, 'decision', ['dismiss', 'remove', 'reopen', 'superseded'])->nullable();
            $t->text('reason')->nullable();
            $t->cid('reviewed_by')->nullable();
            $t->ms('closed_at')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['user_id', 'id']);
            $t->index(['status', 'id']);
            $t->index('review_id');
        });

        $history('review_case_history', 'case_id', 120, 'review_cases');

        Schema::create('claims', function (Blueprint $t) use ($ascii, $state) {
            $t->cid('id', 120)->primary();
            $t->cid('user_id');
            $t->cid('business_id', 120)->nullable();
            $t->string('name', 640);
            $t->string('city', 100)->charset('utf8mb4')->collation('utf8mb4_bin');
            $t->text('address');
            $t->string('contact_name', 400);
            $t->string('phone', 64);
            $t->text('note');
            $state($t, 'status', ['submitted', 'needs_information', 'approved', 'rejected']);
            $t->text('reply')->nullable();
            $t->text('review_reason')->nullable();
            $t->cid('reviewed_by')->nullable();
            $t->ms('reviewed_at')->nullable();
            // evidenceRevision yok/0 ayrimi currentEvidenceProof icin korunur.
            $t->unsignedInteger('evidence_revision')->nullable();
            $t->unsignedInteger('evidence_count')->default(0);
            $t->unsignedInteger('pending_evidence_uploads')->default(0);
            $t->unsignedInteger('total_evidence_uploads')->default(0);
            $t->cid('verification_reference', 120)->nullable();
            $t->cid('mapped_by')->nullable();
            $t->ms('mapped_at')->nullable();
            // claimLocks/{sha256}: acik basvuruyu UNIQUE ile tekillestiren uretilmis kolon. Kapanan basvuru NULL olur.
            $ascii($t, 'lock_key', 64)->nullable()->index();
            $ascii($t, 'open_lock_key', 64)->nullable()->storedAs("IF(`status` IN ('submitted','needs_information'), `lock_key`, NULL)")->unique();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->ms('updated_at')->nullable();
            $t->index(['user_id', 'id']);
            $t->index(['status', 'id']);
            $t->index('business_id');
        });

        $history('claim_history', 'claim_id', 120, 'claims');

        // claims/{id}/evidence/{uuid}
        Schema::create('claim_evidence', function (Blueprint $t) use ($ascii, $state) {
            $t->cid('claim_id', 120);
            $ascii($t, 'id');
            $t->cid('user_id');
            $state($t, 'kind', ['registration', 'authorization', 'workplace']);
            $state($t, 'status', ['uploading', 'ready', 'failed', 'withdrawn', 'purging', 'purged']);
            $ascii($t, 'upload_nonce');
            $t->unsignedInteger('bytes')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->sha256('sha256')->nullable();
            $t->unsignedBigInteger('expires_at_ms')->nullable();
            $t->unsignedBigInteger('created_at_ms');
            $t->ms('ready_at')->nullable();
            $t->ms('failed_at')->nullable();
            $t->ms('withdrawn_at')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->primary(['claim_id', 'id']);
            $t->index(['claim_id', 'status', 'id']);
            $t->index(['status', 'created_at_ms']);
            $t->foreign('claim_id')->references('id')->on('claims')->cascadeOnDelete();
        });

        Schema::create('claim_evidence_views', function (Blueprint $t) use ($ascii) {
            $t->cid('claim_id', 120);
            $ascii($t, 'evidence_id');
            $t->sha256('viewer_key');
            $t->cid('reviewer_id');
            $t->sha256('sha256');
            $t->dateTime('viewed_at', 3)->useCurrent();
            $t->primary(['claim_id', 'evidence_id', 'viewer_key']);
            $t->foreign(['claim_id', 'evidence_id'])->references(['claim_id', 'id'])->on('claim_evidence')->cascadeOnDelete();
        });

        // reviewPhotos/{uuid}
        Schema::create('review_photos', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $t->cid('review_id', 120);
            $t->cid('business_id', 120);
            $t->cid('user_id');
            $t->unsignedInteger('submission_version');
            $t->unsignedInteger('version');
            $state($t, 'status', ['uploading', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn', 'failed', 'purging', 'purged']);
            $t->boolean('rights_attested');
            $ascii($t, 'upload_nonce');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('bytes')->nullable();
            $t->unsignedInteger('thumbnail_bytes')->nullable();
            $t->sha256('sha256')->nullable();
            $t->sha256('thumbnail_sha256')->nullable();
            $t->unsignedBigInteger('expires_at_ms')->nullable();
            $t->text('reason')->nullable();
            $t->cid('reviewed_by')->nullable();
            $t->ms('reviewed_at')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['review_id', 'submission_version']);
            $t->index(['status', 'id']);
        });

        $history('review_photo_history', 'photo_id', 36, 'review_photos');

        Schema::create('review_photo_views', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'photo_id');
            $t->sha256('viewer_key');
            $t->cid('reviewer_id');
            $t->sha256('sha256');
            $t->dateTime('viewed_at', 3)->useCurrent();
            $t->primary(['photo_id', 'viewer_key']);
            $t->foreign('photo_id')->references('id')->on('review_photos')->cascadeOnDelete();
        });

        // businessPhotos/{uuid}
        Schema::create('business_photos', function (Blueprint $t) use ($ascii, $state) {
            $ascii($t, 'id')->primary();
            $t->cid('business_id', 120);
            $t->cid('owner_uid');
            $state($t, 'status', ['uploading', 'pending', 'approved', 'rejected', 'hidden', 'withdrawn', 'failed', 'purging', 'purged']);
            $t->unsignedInteger('version');
            $t->boolean('rights_attested');
            $ascii($t, 'upload_nonce');
            $t->unsignedBigInteger('created_at_ms');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('bytes')->nullable();
            $t->unsignedInteger('thumbnail_bytes')->nullable();
            $t->sha256('sha256')->nullable();
            $t->sha256('thumbnail_sha256')->nullable();
            $t->unsignedBigInteger('expires_at_ms')->nullable();
            $t->unsignedBigInteger('purged_at_ms')->nullable();
            $t->text('reason')->nullable();
            $t->index('business_id');
            $t->index(['status', 'id']);
        });

        $history('business_photo_history', 'photo_id', 36, 'business_photos');

        Schema::create('business_photo_views', function (Blueprint $t) use ($ascii) {
            $ascii($t, 'photo_id');
            $t->sha256('viewer_key');
            $t->cid('user_id');
            $t->sha256('sha256');
            $t->dateTime('viewed_at', 3)->useCurrent();
            $t->primary(['photo_id', 'viewer_key']);
            $t->foreign('photo_id')->references('id')->on('business_photos')->cascadeOnDelete();
        });

        // businessChanges/{businessId}: tek bekleyen revizyon; profil alanlari ayri kolon, diziler *_values.
        Schema::create('business_changes', function (Blueprint $t) use ($ascii, $state) {
            $t->cid('business_id', 120)->primary();
            $ascii($t, 'revision_id');
            $ascii($t, 'expected_revision_id')->nullable();
            $t->unsignedInteger('expected_version');
            $t->cid('user_id');
            $state($t, 'status', ['pending', 'approved', 'rejected']);
            $t->string('profile_name', 640);
            $t->string('profile_city', 100);
            $t->string('profile_district', 400);
            $t->text('profile_address');
            $t->text('profile_phone');
            $t->text('profile_website');
            $t->string('profile_contact_email', 254)->nullable(); // yok ile '' ayrimi korunur
            $t->string('profile_hours', 300);
            $t->text('profile_description');
            $t->double('profile_latitude')->nullable();
            $t->double('profile_longitude')->nullable();
            $t->text('reason')->nullable();
            $t->cid('reviewed_by')->nullable();
            $t->ms('reviewed_at')->nullable();
            $t->dateTime('created_at', 3)->useCurrent();
            $t->index(['status', 'business_id']);
            $t->foreign('business_id')->references('id')->on('businesses');
        });

        Schema::create('business_change_values', function (Blueprint $t) use ($state) {
            $t->cid('business_id', 120);
            $state($t, 'kind', ['category', 'service', 'brand']);
            $t->unsignedSmallInteger('position');
            $t->string('value', 480);
            $t->primary(['business_id', 'kind', 'position']);
            $t->foreign('business_id')->references('business_id')->on('business_changes')->cascadeOnDelete();
        });

        // featureActivations/{businessId}: merge yazildigi icin alanlar bos olabilir.
        Schema::create('feature_activations', function (Blueprint $t) use ($state) {
            $t->cid('business_id', 120)->primary();
            $t->cid('applicant_id')->nullable();
            $state($t, 'status', ['pending', 'approved', 'rejected', 'paused']);
            $t->unsignedInteger('version');
            $t->boolean('feature_quotes')->nullable();
            $t->boolean('feature_appointments')->nullable();
            $t->boolean('monitor_inbox')->nullable();
            $t->boolean('capacity_ready')->nullable();
            $t->text('reason')->nullable();
            $t->ms('updated_at')->nullable();
            $t->index(['status', 'business_id']);
            $t->foreign('business_id')->references('id')->on('businesses');
        });
    }

    public function down(): void
    {
        foreach (['feature_activations', 'business_change_values', 'business_changes', 'business_photo_views', 'business_photo_history', 'business_photos', 'review_photo_views', 'review_photo_history', 'review_photos', 'claim_evidence_views', 'claim_evidence', 'claim_history', 'claims', 'review_case_history', 'review_cases', 'review_reply_history', 'review_replies', 'public_review_photos', 'public_reviews', 'review_history', 'review_photo_refs', 'reviews', 'business_photo_slots'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('business_memberships', function (Blueprint $t) {
            $t->dropColumn(['claim_id', 'granted_by']);
        });
        Schema::table('businesses', function (Blueprint $t) {
            $t->dropUnique(['identity_key']);
            $t->dropColumn(['review_count', 'review_rating_sum', 'photo_attempts', 'photo_attempt_window', 'identity_key', 'created_by', 'duplicate_review_status', 'source']);
        });
    }
};
