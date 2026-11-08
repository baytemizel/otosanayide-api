<?php

use App\Domain\Auth\AuthService;
use App\Domain\Catalogue\SearchProjection;
use App\Domain\Content\Ids;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Yazildi, calistirilmadi: MySQL'e bu fazda baglanilmaz.

function f6mUser(string $email, bool $admin = false, bool $verified = true): array
{
    Queue::fake();
    $service = new AuthService('127.0.0.1');
    $service->register($email, 'correct-horse-1');
    $id = DB::table('auth_accounts')->where('email', $email)->value('id');
    DB::table('auth_accounts')->where('id', $id)->update(['email_verified_at' => $verified ? now('UTC') : null, 'is_admin' => $admin ? 1 : 0]);
    $login = $service->login($email, 'correct-horse-1');

    return [$id, ['HTTP_AUTHORIZATION' => 'Bearer '.$login['session']->accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => '']];
}

function f6mAdmin(string $email = 'admin@example.com'): array
{
    return f6mUser($email, true);
}

function f6mApi($test, string $m, string $u, array $auth, mixed $body = null)
{
    return $test->call($m, $u, [], [], [], $auth, $body === null ? null : json_encode($body));
}

function f6mShop(string $id, array $over = []): void
{
    DB::table('businesses')->insert($over + ['id' => $id, 'name' => 'Usta Motor', 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sanayi Sitesi No 12 Ümraniye', 'phone' => '05321112233', 'website' => '', 'description' => '', 'latitude' => 41.02, 'longitude' => 29.1, 'coordinate_status' => 'verified', 'published' => true]);
    DB::table('business_values')->insert(['business_id' => $id, 'kind' => 'category', 'position' => 0, 'value' => 'mechanical']);
    SearchProjection::sync($id);
}

function f6mMember(string $uid, string $bid, string $role = 'owner', bool $active = true): void
{
    DB::table('business_memberships')->insert(['user_id' => $uid, 'business_id' => $bid, 'role' => $role, 'active' => $active]);
}

function f6mOwned(string $id, string $owner, array $over = []): void
{
    f6mShop($id, $over + ['ownership' => 'verified', 'owner_uid' => $owner]);
    f6mMember($owner, $id);
}

/** Bekleyen yorum: yazar ve isletme verilir. */
function f6mReview(string $author, string $bid = 'b1', array $over = []): string
{
    $id = Ids::sha([$author, $bid]);
    DB::table('reviews')->insert($over + ['id' => $id, 'business_id' => $bid, 'user_id' => $author, 'rating' => 4, 'resolved' => 'yes', 'wc' => 'no', 'text' => 'Sentetik yorum metni, yeterince uzun.', 'status' => 'pending', 'version' => 1, 'submission_version' => 1]);

    return $id;
}

function f6mPublic(string $bid, string $reviewId): void
{
    DB::table('public_reviews')->insert(['business_id' => $bid, 'review_id' => $reviewId, 'rating' => 4, 'resolved' => 'yes', 'wc' => 'no', 'text' => 'Sentetik yorum metni, yeterince uzun.']);
}

function f6mDecision(string $id, int $version, string $decision, array $over = []): array
{
    return $over + ['id' => $id, 'version' => $version, 'decision' => $decision, 'reason' => 'İnceleme tamamlandı, gerekçe yeterli.'];
}

const F6M_GENERIC = 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.';

it('rejects revoked and pre-cutoff tokens on admin routes', function () {
    [$id, $auth] = f6mAdmin();
    DB::table('auth_sessions')->where('account_id', $id)->update(['revoked_at' => now('UTC')]);
    f6mApi($this, 'GET', '/api/admin/reviews', $auth)->assertStatus(401);
    [$id2, $auth2] = f6mAdmin('a2@example.com');
    DB::table('auth_accounts')->where('id', $id2)->update(['tokens_valid_after' => now('UTC')->addHour()]);
    f6mApi($this, 'GET', '/api/admin/claims', $auth2)->assertStatus(401);
});

// Yorum moderasyonu

it('approves a review, publishes the public row and moves the totals once', function () {
    [$uid] = f6mUser('u@example.com');
    [$adminId, $admin] = f6mAdmin();
    f6mShop('b1');
    $id = f6mReview($uid);
    f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($id, 1, 'approve'))->assertStatus(400)->assertExactJson(['error' => 'İçeriği ve otomatik kontrol sonuçlarını incelediğini onayla.']);
    $ok = f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($id, 1, 'approve', ['contentReviewed' => true]))->assertOk()->assertExactJson(['ok' => true]);
    expect($ok->headers->has('Cache-Control'))->toBeFalse();
    $b = DB::table('businesses')->where('id', 'b1')->first();
    expect($b->review_count)->toBe(1)->and($b->review_rating_sum)->toBe(4)->and(DB::table('public_reviews')->count())->toBe(1)->and(DB::table('reviews')->value('reviewed_by'))->toBe($adminId);
    f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($id, 1, 'approve', ['contentReviewed' => true]))->assertStatus(409)->assertExactJson(['error' => 'Yorum değişti. Listeyi yenile.']);
    f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($id, 2, 'hide'))->assertOk();
    expect(DB::table('public_reviews')->count())->toBe(0)->and(DB::table('businesses')->where('id', 'b1')->value('review_count'))->toBe(0);
});

it('blocks own, affiliated and ineligible review approvals', function () {
    [$adminId, $admin] = f6mAdmin();
    [$uid] = f6mUser('u@example.com');
    f6mShop('b1');
    $own = f6mReview($adminId);
    f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($own, 1, 'reject'))->assertStatus(403)->assertExactJson(['error' => 'Kendi yorumunu inceleyemezsin.']);
    f6mMember($adminId, 'b1', 'staff');
    $other = f6mReview($uid);
    f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($other, 1, 'reject'))->assertStatus(403)->assertExactJson(['error' => 'Yetkilisi olduğun işletmenin yorumlarını inceleyemezsin.']);
    [$a2Id, $a2] = f6mAdmin('a2@example.com');
    f6mShop('hidden', ['published' => false]);
    $h = f6mReview($uid, 'hidden');
    f6mApi($this, 'PATCH', '/api/admin/reviews', $a2, f6mDecision($h, 1, 'approve', ['contentReviewed' => true]))->assertStatus(409)->assertExactJson(['error' => 'İşletme veya yorumcu yayın koşullarını karşılamıyor.']);
    expect($a2Id)->not->toBe($adminId);
});

it('waits for a running AI check and fences an erasing author', function () {
    [$uid] = f6mUser('u@example.com');
    [, $admin] = f6mAdmin();
    f6mShop('b1');
    $id = f6mReview($uid, 'b1', ['ai_status' => 'running', 'ai_requested_at_ms' => Ids::nowMs()]);
    f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($id, 1, 'approve', ['contentReviewed' => true]))->assertStatus(409)->assertExactJson(['error' => 'AI kontrolü tamamlanmadan yayın kararı verilemez.']);
    DB::table('account_fences')->insert(['owner_uid' => $uid, 'job_id' => (string) Str::uuid(), 'epoch' => (string) Str::uuid(), 'state' => 'deleting', 'created_at_ms' => 1]);
    f6mApi($this, 'PATCH', '/api/admin/reviews', $admin, f6mDecision($id, 1, 'reject'))->assertStatus(409)->assertExactJson(['error' => F6M_GENERIC]);
});

it('lists the review queue with automatic results and no identity', function () {
    [$uid] = f6mUser('u@example.com');
    [, $admin] = f6mAdmin();
    f6mShop('b1');
    f6mReview($uid);
    $r = f6mApi($this, 'GET', '/api/admin/reviews', $admin)->assertOk();
    expect($r->headers->get('Cache-Control'))->toBe('no-store')->and($r->json('reviews.0.businessId'))->toBe('b1')->and($r->json('reviews.0'))->not->toHaveKey('userId')->and($r->json('reviews.0.automatic.ai.status'))->toBe('not_requested')->and($r->json('aiEnabled'))->toBeFalse();
    f6mApi($this, 'GET', '/api/admin/reviews', f6mUser('x@example.com')[1])->assertStatus(403);
});

// Yanit

it('publishes and hides replies with independent review', function () {
    [$uid] = f6mUser('u@example.com');
    [$ownerId, $owner] = f6mUser('o@example.com');
    [$adminId, $admin] = f6mAdmin();
    f6mOwned('b1', $ownerId);
    $rid = f6mReview($uid, 'b1', ['status' => 'approved']);
    f6mPublic('b1', $rid);
    DB::table('review_replies')->insert(['review_id' => $rid, 'business_id' => 'b1', 'user_id' => $ownerId, 'text' => 'Teşekkür ederiz, örnek yanıt.', 'status' => 'pending', 'version' => 1]);
    f6mApi($this, 'PATCH', '/api/admin/replies', $owner, f6mDecision($rid, 1, 'approve', ['contentReviewed' => true]))->assertStatus(403);
    f6mApi($this, 'PATCH', '/api/admin/replies', $admin, f6mDecision($rid, 2, 'approve', ['contentReviewed' => true]))->assertStatus(409)->assertExactJson(['error' => 'Yanıt değişti. Listeyi yenile.']);
    f6mApi($this, 'PATCH', '/api/admin/replies', $admin, f6mDecision($rid, 1, 'approve', ['contentReviewed' => true]))->assertOk();
    expect(DB::table('public_reviews')->value('reply_text'))->toBe('Teşekkür ederiz, örnek yanıt.');
    f6mApi($this, 'PATCH', '/api/admin/replies', $admin, f6mDecision($rid, 2, 'hide'))->assertOk();
    expect(DB::table('public_reviews')->value('reply_text'))->toBeNull()->and(DB::table('review_replies')->value('reviewed_by'))->toBe($adminId);
    expect(f6mApi($this, 'GET', '/api/admin/replies?status=pending', $admin)->json('replies'))->toBe([]);
});

it('a reply reviewer affiliated with the business is refused', function () {
    [$uid] = f6mUser('u@example.com');
    [$adminId, $admin] = f6mAdmin();
    f6mOwned('b1', $adminId);
    $rid = f6mReview($uid, 'b1', ['status' => 'approved']);
    f6mPublic('b1', $rid);
    DB::table('review_replies')->insert(['review_id' => $rid, 'business_id' => 'b1', 'user_id' => $adminId, 'text' => 'Teşekkür ederiz, örnek yanıt.', 'status' => 'pending', 'version' => 1]);
    f6mApi($this, 'PATCH', '/api/admin/replies', $admin, f6mDecision($rid, 1, 'reject'))->assertStatus(403)->assertExactJson(['error' => 'Kendi yanıtını inceleyemezsin.']);
});

// Basvurular

it('case decisions hide reports and reject stale or conflicted reviewers', function () {
    [$author] = f6mUser('u@example.com');
    [$reporter, $reporterAuth] = f6mUser('r@example.com');
    [, $admin] = f6mAdmin();
    f6mShop('b1', ['review_count' => 1, 'review_rating_sum' => 4]);
    $rid = f6mReview($author, 'b1', ['status' => 'approved']);
    f6mPublic('b1', $rid);
    DB::table('review_cases')->insert(['id' => 'case1', 'review_id' => $rid, 'business_id' => 'b1', 'user_id' => $reporter, 'kind' => 'report', 'text' => 'Sentetik rapor metni uzun.', 'review_version' => 1, 'status' => 'open']);
    $body = ['id' => 'case1', 'decision' => 'remove', 'reason' => 'İnceleme tamamlandı, gerekçe yeterli.', 'reviewed' => true];
    f6mApi($this, 'PATCH', '/api/admin/review-cases', $reporterAuth, $body)->assertStatus(403);
    expect(f6mApi($this, 'GET', '/api/admin/review-cases', $admin)->json('items.0.currentVersion'))->toBe(1);
    f6mApi($this, 'PATCH', '/api/admin/review-cases', $admin, $body)->assertOk()->assertExactJson(['ok' => true]);
    $r = DB::table('reviews')->where('id', $rid)->first();
    expect($r->status)->toBe('hidden')->and((bool) $r->moderation_hold)->toBeTrue()->and(DB::table('public_reviews')->count())->toBe(0)->and(DB::table('review_cases')->value('status'))->toBe('closed');
    f6mApi($this, 'PATCH', '/api/admin/review-cases', $admin, $body)->assertStatus(409)->assertExactJson(['error' => 'Başvuru zaten sonuçlandı.']);
});

it('appeals requeue and a prior moderator may not decide them', function () {
    [$author] = f6mUser('u@example.com');
    [$mod, $modAuth] = f6mAdmin();
    [, $other] = f6mAdmin('a2@example.com');
    f6mShop('b1');
    $rid = f6mReview($author, 'b1', ['status' => 'hidden', 'moderation_hold' => true, 'reviewed_by' => $mod, 'version' => 3]);
    DB::table('review_photo_refs')->insert(['review_id' => $rid, 'kind' => 'slot', 'position' => 0, 'photo_id' => (string) Str::uuid()]);
    DB::table('review_cases')->insert(['id' => 'case1', 'review_id' => $rid, 'business_id' => 'b1', 'user_id' => $author, 'kind' => 'appeal', 'text' => 'Sentetik itiraz metni uzun.', 'review_version' => 3, 'status' => 'open']);
    $body = ['id' => 'case1', 'decision' => 'reopen', 'reason' => 'İnceleme tamamlandı, gerekçe yeterli.', 'reviewed' => true];
    f6mApi($this, 'PATCH', '/api/admin/review-cases', $modAuth, $body)->assertStatus(409)->assertExactJson(['error' => 'Bu başvuruyu başka bir yönetici incelemeli.']);
    f6mApi($this, 'PATCH', '/api/admin/review-cases', $other, $body)->assertOk();
    $r = DB::table('reviews')->where('id', $rid)->first();
    expect($r->status)->toBe('pending')->and((int) $r->submission_version)->toBe(4)->and((bool) $r->moderation_hold)->toBeFalse()->and(DB::table('review_photo_refs')->count())->toBe(0)->and($r->local_policy_version)->toBeNull();
});

// Yorum fotograflari

it('review photo approval needs the full-image view and independence', function () {
    [$uid] = f6mUser('u@example.com');
    [$adminId, $admin] = f6mAdmin();
    f6mShop('b1');
    $rid = f6mReview($uid, 'b1', ['status' => 'approved']);
    f6mPublic('b1', $rid);
    $photo = (string) Str::uuid();
    $sha = str_repeat('a', 64);
    DB::table('review_photos')->insert(['id' => $photo, 'review_id' => $rid, 'business_id' => 'b1', 'user_id' => $uid, 'submission_version' => 1, 'version' => 1, 'status' => 'pending', 'rights_attested' => true, 'upload_nonce' => (string) Str::uuid(), 'created_at_ms' => 1, 'sha256' => $sha, 'expires_at_ms' => Ids::nowMs() + 60000]);
    $d = f6mDecision($photo, 1, 'approve', ['attested' => true]);
    f6mApi($this, 'PATCH', '/api/admin/review-photos', $admin, $d)->assertStatus(409)->assertExactJson(['error' => 'Fotoğrafı tam boyutta incele; yorum ve yayın koşullarını kontrol et.']);
    DB::table('review_photo_views')->insert(['photo_id' => $photo, 'viewer_key' => Ids::viewKey($adminId), 'reviewer_id' => $adminId, 'sha256' => $sha]);
    f6mApi($this, 'PATCH', '/api/admin/review-photos', $admin, $d)->assertOk();
    expect(DB::table('public_review_photos')->value('photo_id'))->toBe($photo)->and(DB::table('review_photos')->value('expires_at_ms'))->toBeNull();
    f6mApi($this, 'PATCH', '/api/admin/review-photos', $admin, f6mDecision($photo, 1, 'hide', ['attested' => true]))->assertStatus(409);
    f6mApi($this, 'PATCH', '/api/admin/review-photos', $admin, f6mDecision($photo, 2, 'hide', ['attested' => true]))->assertOk();
    expect(DB::table('public_review_photos')->count())->toBe(0);
});

it('review photo uploader and affiliated admins are refused', function () {
    [$adminId, $admin] = f6mAdmin();
    [$uid] = f6mUser('u@example.com');
    f6mShop('b1');
    $rid = f6mReview($adminId);
    $photo = (string) Str::uuid();
    DB::table('review_photos')->insert(['id' => $photo, 'review_id' => $rid, 'business_id' => 'b1', 'user_id' => $adminId, 'submission_version' => 1, 'version' => 1, 'status' => 'pending', 'rights_attested' => true, 'upload_nonce' => (string) Str::uuid(), 'created_at_ms' => 1]);
    f6mApi($this, 'PATCH', '/api/admin/review-photos', $admin, f6mDecision($photo, 1, 'reject', ['attested' => false]))->assertStatus(403)->assertExactJson(['error' => 'Kendi fotoğrafını inceleyemezsin.']);
    expect($uid)->not->toBe($adminId);
});

// Isletme fotograflari

it('business photo approval is independent, needs the view and syncs search', function () {
    [$ownerId, $owner] = f6mUser('o@example.com');
    [$adminId, $admin] = f6mAdmin();
    f6mOwned('b1', $ownerId);
    $photo = (string) Str::uuid();
    $sha = str_repeat('b', 64);
    DB::table('business_photos')->insert(['id' => $photo, 'business_id' => 'b1', 'owner_uid' => $ownerId, 'status' => 'pending', 'version' => 1, 'rights_attested' => true, 'upload_nonce' => (string) Str::uuid(), 'created_at_ms' => 1, 'sha256' => $sha, 'expires_at_ms' => Ids::nowMs() + 60000, 'width' => 40, 'height' => 30, 'bytes' => 100]);
    DB::table('business_photo_slots')->insert(['business_id' => 'b1', 'position' => 0, 'photo_id' => $photo]);
    $d = f6mDecision($photo, 1, 'approve', ['attested' => true]);
    f6mApi($this, 'PATCH', '/api/admin/business-photos', $owner, $d)->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
    f6mApi($this, 'PATCH', '/api/admin/business-photos', $admin, $d)->assertStatus(409)->assertExactJson(['error' => 'Fotoğrafı tam boyutta incele; sahiplik ve yayın koşullarını kontrol et.']);
    DB::table('business_photo_views')->insert(['photo_id' => $photo, 'viewer_key' => Ids::viewKey($adminId), 'user_id' => $adminId, 'sha256' => $sha]);
    $ok = f6mApi($this, 'PATCH', '/api/admin/business-photos', $admin, $d)->assertOk()->assertExactJson(['ok' => true]);
    expect($ok->headers->get('Cache-Control'))->toBe('private, no-store')->and(DB::table('businesses')->where('id', 'b1')->value('business_cover_photo_id'))->toBe($photo)->and(DB::table('business_public_photos')->value('photo_id'))->toBe($photo);
    expect(f6mApi($this, 'GET', '/api/admin/business-photos?status=approved', $admin)->json('items.0.businessId'))->toBe('b1');
    f6mApi($this, 'PATCH', '/api/admin/business-photos', $admin, f6mDecision($photo, 2, 'hide', ['attested' => true]))->assertOk();
    expect(DB::table('businesses')->where('id', 'b1')->value('business_cover_photo_id'))->toBeNull()->and(DB::table('business_public_photos')->count())->toBe(0);
});

it('an admin member of the business may not review its photos', function () {
    [$ownerId] = f6mUser('o@example.com');
    [$adminId, $admin] = f6mAdmin();
    f6mOwned('b1', $ownerId);
    f6mMember($adminId, 'b1', 'staff');
    $photo = (string) Str::uuid();
    DB::table('business_photos')->insert(['id' => $photo, 'business_id' => 'b1', 'owner_uid' => $ownerId, 'status' => 'pending', 'version' => 1, 'rights_attested' => true, 'upload_nonce' => (string) Str::uuid(), 'created_at_ms' => 1]);
    f6mApi($this, 'PATCH', '/api/admin/business-photos', $admin, f6mDecision($photo, 1, 'reject', ['attested' => false]))->assertStatus(403)->assertExactJson(['error' => 'Kendi veya yetkili olduğun işletmenin fotoğrafını inceleyemezsin.']);
});

// Profil degisikligi

function f6mProfileBody(): array
{
    return ['name' => 'Yeni Motor Servisi', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'address' => 'Test sokak, test adresi 10', 'phone' => '05321112233', 'website' => '', 'hours' => '', 'description' => '', 'categories' => ['mechanical'], 'services' => ['Fren'], 'brands' => ['BMW'], 'location' => ['latitude' => 41.02, 'longitude' => 29.1]];
}

it('a profile change is applied only by another admin and syncs search', function () {
    [$ownerId, $owner] = f6mUser('o@example.com');
    [, $admin] = f6mAdmin();
    f6mOwned('b1', $ownerId);
    $revision = f6mApi($this, 'PATCH', '/api/businesses/manage', $owner, ['businessId' => 'b1', 'expectedVersion' => 0, 'expectedRevisionId' => null, 'profile' => f6mProfileBody()])->assertOk()->json('revisionId');
    expect(f6mApi($this, 'GET', '/api/admin/business-changes', $admin)->json('changes.0.profile.name'))->toBe('Yeni Motor Servisi');
    $d = ['businessId' => 'b1', 'revisionId' => $revision, 'decision' => 'approve', 'reason' => 'İnceleme tamamlandı, gerekçe yeterli.'];
    f6mApi($this, 'PATCH', '/api/admin/business-changes', $owner, $d)->assertStatus(403);
    f6mApi($this, 'PATCH', '/api/admin/business-changes', $admin, [...$d, 'revisionId' => (string) Str::uuid()])->assertStatus(409)->assertExactJson(['error' => 'İnceleme değişti veya sonuçlandı.']);
    f6mApi($this, 'PATCH', '/api/admin/business-changes', $admin, $d)->assertOk();
    $b = DB::table('businesses')->where('id', 'b1')->first();
    expect($b->name)->toBe('Yeni Motor Servisi')->and($b->phone)->toBe('+905321112233')->and($b->profile_version)->toBe(1)->and($b->coordinate_status)->toBe('unverified');
    expect(DB::table('business_search')->where('business_id', 'b1')->count())->toBe(0);
    f6mApi($this, 'PATCH', '/api/admin/business-changes', $admin, $d)->assertStatus(409);
});

it('refuses a self-submitted change and a stale profile version', function () {
    [$adminId, $admin] = f6mAdmin();
    [, $other] = f6mAdmin('a2@example.com');
    f6mOwned('b1', $adminId);
    $revision = f6mApi($this, 'PATCH', '/api/businesses/manage', $admin, ['businessId' => 'b1', 'expectedVersion' => 0, 'expectedRevisionId' => null, 'profile' => f6mProfileBody()])->json('revisionId');
    $d = ['businessId' => 'b1', 'revisionId' => $revision, 'decision' => 'approve', 'reason' => 'İnceleme tamamlandı, gerekçe yeterli.'];
    f6mApi($this, 'PATCH', '/api/admin/business-changes', $admin, $d)->assertStatus(403)->assertExactJson(['error' => 'Kendi değişikliğini değerlendiremezsin.']);
    DB::table('businesses')->where('id', 'b1')->update(['profile_version' => 5]);
    f6mApi($this, 'PATCH', '/api/admin/business-changes', $other, $d)->assertStatus(409)->assertExactJson(['error' => 'Profil sürümü değişmiş.']);
});

// Katalog

function f6mChecks(): array
{
    $proof = ['checked' => true, 'reference' => 'Manually reviewed evidence reference'];

    return ['rights' => $proof, 'identity' => $proof, 'location' => $proof, 'duplicates' => $proof];
}

it('publishes and withdraws with attestations, version checks and search sync', function () {
    [, $admin] = f6mAdmin();
    f6mShop('b1', ['published' => false, 'coordinate_status' => 'unverified']);
    expect(DB::table('business_search')->count())->toBe(0);
    $publish = ['businessId' => 'b1', 'version' => 0, 'profileVersion' => 0, 'action' => 'publish', 'reason' => 'Kanıtlar yönetici tarafından incelendi.', 'checks' => f6mChecks()];
    f6mApi($this, 'PATCH', '/api/admin/catalogue', $admin, [...$publish, 'version' => 3])->assertStatus(409)->assertExactJson(['error' => 'Yayın koşulları sağlanmadı veya kayıt değişti. Bilgileri yeniden kontrol et.']);
    f6mApi($this, 'PATCH', '/api/admin/catalogue', $admin, $publish)->assertOk();
    expect(DB::table('business_search')->where('business_id', 'b1')->count())->toBe(1);
    $b = DB::table('businesses')->where('id', 'b1')->first();
    expect((bool) $b->published)->toBeTrue()->and($b->coordinate_status)->toBe('verified')->and($b->publication_version)->toBe(1);
    f6mApi($this, 'PATCH', '/api/admin/catalogue', $admin, ['businessId' => 'b1', 'version' => 1, 'profileVersion' => 0, 'action' => 'withdraw', 'reason' => 'Adres yeniden incelenecek, kayıt alındı.'])->assertOk();
    expect(DB::table('business_search')->count())->toBe(0);
    $items = f6mApi($this, 'GET', '/api/admin/catalogue/history?businessId=b1', $admin)->assertOk()->json('items');
    expect($items[0]['action'])->toBe('withdraw')->and($items[1]['checks'])->toHaveCount(4)->and($items[0])->not->toHaveKey('actorId');
    expect(f6mApi($this, 'GET', '/api/admin/catalogue?published=false', $admin)->json('items.0.id'))->toBe('b1');
});

it('refuses publication by an affiliated admin or for a sample', function () {
    [$adminId, $admin] = f6mAdmin();
    f6mShop('a', ['published' => false]);
    f6mMember($adminId, 'a', 'staff');
    f6mShop('s', ['published' => false, 'sample' => true]);
    foreach (['a', 's'] as $id) {
        f6mApi($this, 'PATCH', '/api/admin/catalogue', $admin, ['businessId' => $id, 'version' => 0, 'profileVersion' => 0, 'action' => 'publish', 'reason' => 'Kanıtlar yönetici tarafından incelendi.', 'checks' => f6mChecks()])->assertStatus(409);
    }
    f6mApi($this, 'PATCH', '/api/admin/catalogue', $admin, ['businessId' => 'nope', 'version' => 0, 'profileVersion' => 0, 'action' => 'withdraw', 'reason' => 'Adres yeniden incelenecek, kayıt alındı.'])->assertStatus(409)->assertExactJson(['error' => F6M_GENERIC]);
});

it('drafts apply only to unclaimed unpublished records and expose safe evidence', function () {
    [$ownerId] = f6mUser('o@example.com');
    [, $admin] = f6mAdmin();
    $src = str_repeat('a', 24);
    f6mShop('d1', ['published' => false, 'coordinate_status' => 'unverified', 'import_source_id' => $src]);
    DB::table('catalogue_sources')->insert(['id' => $src, 'business_id' => 'd1', 'candidate_id' => str_repeat('b', 24), 'source_row' => 1, 'source_hash' => str_repeat('c', 64), 'name' => 'Kaynak', 'city' => 'İstanbul', 'district' => 'X', 'address' => 'Adres', 'phone' => null, 'contact_email' => null, 'website' => 'https://user:secret@example.com', 'raw_source' => json_encode(['private' => 'hidden']), 'extra' => json_encode(['source' => 'https://example.com/s', 'coordinateEvidence' => ['latitude' => 41, 'longitude' => 29]])]);
    f6mOwned('own', $ownerId, ['published' => false]);
    $r = f6mApi($this, 'GET', '/api/admin/catalogue/draft?businessId=d1', $admin)->assertOk();
    expect($r->json('evidence.website'))->toBeNull()->and($r->json('evidence.source'))->toBe('https://example.com/s')->and($r->json('evidence'))->not->toHaveKey('rawSource')->and($r->json('evidence.coordinates.latitude'))->toBe(41);
    f6mApi($this, 'GET', '/api/admin/catalogue/draft?businessId=own', $admin)->assertStatus(403)->assertExactJson(['error' => 'Bu kayıt taslak düzenlemeye uygun değil.']);
    $save = ['businessId' => 'd1', 'profileVersion' => 0, 'publicationVersion' => 0, 'reason' => 'Kaynak bilgileri incelendi.', 'profile' => f6mProfileBody()];
    f6mApi($this, 'PATCH', '/api/admin/catalogue/draft', $admin, $save)->assertOk();
    expect(DB::table('businesses')->where('id', 'd1')->value('profile_version'))->toBe(1)->and((bool) DB::table('businesses')->where('id', 'd1')->value('published'))->toBeFalse();
    f6mApi($this, 'PATCH', '/api/admin/catalogue/draft', $admin, $save)->assertStatus(409)->assertExactJson(['error' => 'Kayıt değişti veya düzenleme yetkisi yok. Yeniden yükle.']);
});

it('pages history by time and refuses a missing cursor', function () {
    [, $admin] = f6mAdmin();
    f6mShop('b1');
    foreach (range(1, 21) as $i) {
        DB::table('business_history')->insert(['business_id' => 'b1', 'id' => 'h'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'kind' => 'catalogue_draft', 'details' => json_encode(['reason' => 'x']), 'created_at' => '2026-01-01 00:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.000']);
    }
    $first = f6mApi($this, 'GET', '/api/admin/catalogue/history?businessId=b1', $admin)->json();
    expect($first['items'])->toHaveCount(20)->and($first['items'][0]['createdAt'])->toBe('2026-01-01T00:00:21.000Z')->and($first['next'])->toBe('h002');
    expect(f6mApi($this, 'GET', '/api/admin/catalogue/history?businessId=b1&after=h002', $admin)->json('items.0.id'))->toBe('h001');
    f6mApi($this, 'GET', '/api/admin/catalogue/history?businessId=b1&after=nope', $admin)->assertStatus(400)->assertExactJson(['error' => 'Geçmiş sayfası değişti. İlk sayfadan yeniden başla.']);
    f6mApi($this, 'GET', '/api/admin/catalogue/history?businessId=nope', $admin)->assertStatus(404);
});

// Sahiplik

function f6mClaim(string $uid, array $over = []): string
{
    $id = 'claim'.Str::random(6);
    DB::table('claims')->insert($over + ['id' => $id, 'user_id' => $uid, 'name' => 'Usta Motor', 'city' => 'İstanbul', 'address' => 'Sanayi Sitesi No 12', 'contact_name' => 'Test Kişi', 'phone' => '05550100000', 'note' => '', 'status' => 'submitted']);

    return $id;
}

function f6mVerifiedFixture(string $uid, string $adminId): array
{
    f6mShop('b1');
    $claim = f6mClaim($uid, ['business_id' => 'b1', 'evidence_revision' => 1, 'evidence_count' => 1]);
    $eid = (string) Str::uuid();
    $sha = str_repeat('d', 64);
    DB::table('claim_evidence')->insert(['claim_id' => $claim, 'id' => $eid, 'user_id' => $uid, 'kind' => 'workplace', 'status' => 'ready', 'upload_nonce' => (string) Str::uuid(), 'sha256' => $sha, 'bytes' => 10, 'width' => 1, 'height' => 1, 'expires_at_ms' => Ids::nowMs() + 600000, 'created_at_ms' => 1]);
    DB::table('claim_evidence_views')->insert(['claim_id' => $claim, 'evidence_id' => $eid, 'viewer_key' => Ids::viewKey($adminId), 'reviewer_id' => $adminId, 'sha256' => $sha]);

    return [$claim, $eid];
}

it('verifies evidence and approves ownership with a matching proof', function () {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'otosanayide.flags.claim_evidence' => true]);
    [$uid, $userAuth] = f6mUser('u@example.com');
    [$adminId, $admin] = f6mAdmin();
    [$claim, $eid] = f6mVerifiedFixture($uid, $adminId);
    $verify = ['claimId' => $claim, 'evidenceIds' => [$eid], 'evidenceRevision' => 1, 'attested' => true, 'reason' => 'Belge kontrol edildi, tutarlı.'];
    f6mApi($this, 'POST', '/api/admin/claim-evidence', $userAuth, $verify)->assertStatus(403);
    f6mApi($this, 'POST', '/api/admin/claim-evidence', $admin, [...$verify, 'evidenceRevision' => 9])->assertStatus(409)->assertExactJson(['error' => 'Belge listesi değişti. Tüm güncel belgeleri yeniden aç.']);
    $proof = f6mApi($this, 'POST', '/api/admin/claim-evidence', $admin, $verify)->assertOk()->json('verificationReference');
    expect(DB::table('claims')->where('id', $claim)->value('verification_reference'))->toBe($proof)->and(DB::table('claim_verification_evidence')->value('evidence_id'))->toBe($eid);
    $approve = ['claimId' => $claim, 'decision' => 'approve', 'reason' => 'İşletme yetkilisi kanıtı incelendi.', 'evidenceChecked' => true, 'verificationReference' => $proof];
    f6mApi($this, 'PATCH', '/api/admin/claims', $admin, [...$approve, 'verificationReference' => 'nope'])->assertStatus(409)->assertExactJson(['error' => 'Bu başvuruya bağlı doğrulanmış kanıt kaydı gerekli.']);
    f6mApi($this, 'PATCH', '/api/admin/claims', $admin, $approve)->assertOk()->assertExactJson(['id' => $claim, 'status' => 'approved']);
    $b = DB::table('businesses')->where('id', 'b1')->first();
    expect($b->ownership)->toBe('verified')->and($b->owner_uid)->toBe($uid)->and(DB::table('business_memberships')->where('user_id', $uid)->value('role'))->toBe('owner');
    f6mApi($this, 'PATCH', '/api/admin/claims', $admin, $approve)->assertStatus(409)->assertExactJson(['error' => 'Bu başvuru daha önce sonuçlandırılmış.']);
});

it('evidence verification requires this admin to have opened the file', function () {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'otosanayide.flags.claim_evidence' => true]);
    [$uid] = f6mUser('u@example.com');
    [$adminId] = f6mAdmin();
    [, $second] = f6mAdmin('a2@example.com');
    [$claim, $eid] = f6mVerifiedFixture($uid, $adminId);
    f6mApi($this, 'POST', '/api/admin/claim-evidence', $second, ['claimId' => $claim, 'evidenceIds' => [$eid], 'evidenceRevision' => 1, 'attested' => true, 'reason' => 'Belge kontrol edildi, tutarlı.'])->assertStatus(409)->assertExactJson(['error' => 'Her güncel belgeyi tam boyutta açıp kontrol etmelisin.']);
});

it('a reviewer may not decide or map a claim they filed', function () {
    [$adminId, $admin] = f6mAdmin();
    f6mShop('b1');
    $claim = f6mClaim($adminId, ['business_id' => 'b1']);
    f6mApi($this, 'PATCH', '/api/admin/claims', $admin, ['claimId' => $claim, 'decision' => 'reject', 'reason' => 'Sahiplik kanıtlanamadı, kayıt.'])->assertStatus(409)->assertExactJson(['error' => 'Kendi başvurunu değerlendiremezsin.']);
    $own = f6mClaim($adminId);
    f6mApi($this, 'POST', '/api/admin/claim-targets', $admin, ['mode' => 'existing', 'claimId' => $own, 'reason' => 'Eşleme gerekçesi yeterli.', 'identityChecked' => true, 'businessId' => 'b1'])->assertStatus(409)->assertExactJson(['error' => 'Başvuru eşlemeye uygun değil veya daha önce bağlanmış.']);
});

it('requests information and bumps the evidence revision', function () {
    [$uid] = f6mUser('u@example.com');
    [, $admin] = f6mAdmin();
    $claim = f6mClaim($uid, ['evidence_revision' => 2]);
    f6mApi($this, 'PATCH', '/api/admin/claims', $admin, ['claimId' => $claim, 'decision' => 'request_information', 'reason' => 'Güncel yetki belgesi gerekli.'])->assertOk();
    $c = DB::table('claims')->where('id', $claim)->first();
    expect($c->status)->toBe('needs_information')->and($c->evidence_revision)->toBe(3)->and($c->review_reason)->toBe('Güncel yetki belgesi gerekli.');
    expect(f6mApi($this, 'GET', '/api/admin/claims', $admin)->json('claims.0.id'))->toBe($claim);
});

it('maps claims to existing or new unpublished businesses with a UNIQUE identity', function () {
    [$uid] = f6mUser('u@example.com');
    [$u2] = f6mUser('v@example.com');
    [, $admin] = f6mAdmin();
    f6mShop('free', ['published' => false]);
    f6mShop('taken', ['ownership' => 'verified', 'owner_uid' => 'someone']);
    expect(f6mApi($this, 'GET', '/api/admin/claim-targets?businessId=free', $admin)->json('business.eligible'))->toBeTrue();
    f6mApi($this, 'GET', '/api/admin/claim-targets?businessId=none', $admin)->assertStatus(404)->assertExactJson(['error' => 'İşletme kaydı bulunamadı.']);
    $c1 = f6mClaim($uid);
    f6mApi($this, 'POST', '/api/admin/claim-targets', $admin, ['mode' => 'existing', 'claimId' => $c1, 'reason' => 'Eşleme gerekçesi yeterli.', 'identityChecked' => true, 'businessId' => 'taken'])->assertStatus(409)->assertExactJson(['error' => 'Seçilen işletme sahiplenmeye uygun değil.']);
    f6mApi($this, 'POST', '/api/admin/claim-targets', $admin, ['mode' => 'existing', 'claimId' => $c1, 'reason' => 'Eşleme gerekçesi yeterli.', 'identityChecked' => true, 'businessId' => 'free'])->assertOk()->assertExactJson(['businessId' => 'free']);
    $c2 = f6mClaim($uid);
    $new = ['mode' => 'new', 'claimId' => $c2, 'reason' => 'Eşleme gerekçesi yeterli.', 'identityChecked' => true, 'duplicateChecked' => true, 'profile' => f6mProfileBody()];
    $r = f6mApi($this, 'POST', '/api/admin/claim-targets', $admin, $new)->assertOk();
    expect($r->json('published'))->toBeFalse()->and($r->json('businessId'))->toBe('new-'.substr(hash('sha256', $c2), 0, 32));
    $b = DB::table('businesses')->where('id', $r->json('businessId'))->first();
    expect((bool) $b->published)->toBeFalse()->and($b->source)->toBe('admin_claim_review')->and($b->coordinate_status)->toBe('unverified');
    $c3 = f6mClaim($u2);
    f6mApi($this, 'POST', '/api/admin/claim-targets', $admin, [...$new, 'claimId' => $c3])->assertStatus(409)->assertExactJson(['error' => 'Aynı bilgilerle daha önce kayıt oluşturulmuş. Mevcut işletmeyi incele.']);
    expect(fn () => DB::table('businesses')->insert(['id' => 'dup', 'name' => 'n', 'city' => 'İstanbul', 'district' => 'd', 'address' => 'a', 'phone' => '', 'website' => '', 'description' => '', 'identity_key' => $b->identity_key]))->toThrow(UniqueConstraintViolationException::class);
});

// AI

it('analyze applies a checked result and never resends an uncertain one', function () {
    config(['otosanayide.flags.ai_moderation' => true, 'otosanayide.openai_api_key' => 'synthetic-key']);
    [$uid] = f6mUser('u@example.com');
    [, $admin] = f6mAdmin();
    f6mShop('b1');
    $id = f6mReview($uid);
    Http::fake(['api.openai.com/*' => Http::response(['model' => 'test-model', 'results' => [['flagged' => false, 'categories' => ['harassment' => false]], ['flagged' => true, 'categories' => ['harassment' => true]]]])]);
    $r = f6mApi($this, 'POST', '/api/admin/reviews/analyze', $admin, ['id' => $id, 'version' => 1])->assertOk();
    expect($r->json('automatic.ai'))->toBe(['status' => 'checked', 'model' => 'test-model', 'flagged' => true, 'categories' => ['harassment']])->and(DB::table('reviews')->value('ai_status'))->toBe('checked')->and(DB::table('account_external_operations')->value('state'))->toBe('settled');
    f6mApi($this, 'POST', '/api/admin/reviews/analyze', $admin, ['id' => $id, 'version' => 1])->assertStatus(409)->assertExactJson(['error' => 'Bu sürüm için AI kontrolü zaten tamamlandı.']);
});

it('an unavailable AI result stays uncertain and blocks a retry', function () {
    config(['otosanayide.flags.ai_moderation' => true, 'otosanayide.openai_api_key' => 'synthetic-key']);
    [$uid] = f6mUser('u@example.com');
    [, $admin] = f6mAdmin();
    f6mShop('b1');
    $id = f6mReview($uid);
    Http::fake(['api.openai.com/*' => Http::failedConnection()]);
    f6mApi($this, 'POST', '/api/admin/reviews/analyze', $admin, ['id' => $id, 'version' => 1])->assertOk();
    expect(DB::table('account_external_operations')->value('state'))->toBe('uncertain');
    DB::table('reviews')->where('id', $id)->update(['ai_requested_at_ms' => 1]);
    f6mApi($this, 'POST', '/api/admin/reviews/analyze', $admin, ['id' => $id, 'version' => 1])->assertStatus(500);
    expect(DB::table('account_external_operations')->count())->toBe(1);
});

it('analyze refuses the author, an affiliated admin and a stale version', function () {
    config(['otosanayide.flags.ai_moderation' => true, 'otosanayide.openai_api_key' => 'synthetic-key']);
    [$adminId, $admin] = f6mAdmin();
    [$uid] = f6mUser('u@example.com');
    f6mShop('b1');
    $own = f6mReview($adminId);
    f6mApi($this, 'POST', '/api/admin/reviews/analyze', $admin, ['id' => $own, 'version' => 1])->assertStatus(403)->assertExactJson(['error' => 'Kendi yorumunu inceleyemezsin.']);
    f6mMember($adminId, 'b1', 'staff');
    $other = f6mReview($uid);
    f6mApi($this, 'POST', '/api/admin/reviews/analyze', $admin, ['id' => $other, 'version' => 1])->assertStatus(403)->assertExactJson(['error' => 'Bu yorumu inceleyemezsin.']);
    f6mApi($this, 'POST', '/api/admin/reviews/analyze', $admin, ['id' => $other, 'version' => 9])->assertStatus(409)->assertExactJson(['error' => 'Yorum değişti veya sonuçlandı.']);
});
