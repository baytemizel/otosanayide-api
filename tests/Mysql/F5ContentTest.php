<?php

use App\Domain\Auth\AuthService;
use App\Domain\Catalogue\SearchProjection;
use App\Domain\Content\Ids;
use App\Domain\Content\MediaStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Bu dosya yazildi ama calistirilmadi: MySQL'e bu fazda baglanilmaz.

function f5mUser(string $email = 'u@example.com', bool $verified = true, bool $admin = false): array
{
    Queue::fake();
    $service = new AuthService('127.0.0.1');
    $service->register($email, 'correct-horse-1');
    $id = DB::table('auth_accounts')->where('email', $email)->value('id');
    DB::table('auth_accounts')->where('id', $id)->update(['email_verified_at' => $verified ? now('UTC') : null, 'is_admin' => $admin ? 1 : 0]);
    $login = $service->login($email, 'correct-horse-1');

    return [$id, ['HTTP_AUTHORIZATION' => 'Bearer '.$login['session']->accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => '']];
}

function f5mApi($test, string $m, string $u, array $auth, mixed $body = null)
{
    return $test->call($m, $u, [], [], [], $auth, $body === null ? null : json_encode($body));
}

function f5mShop(string $id, array $over = []): void
{
    DB::table('businesses')->insert($over + ['id' => $id, 'name' => 'Usta Motor', 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sanayi Sitesi No 12 Ümraniye', 'phone' => '', 'website' => '', 'description' => '', 'latitude' => 41.02, 'longitude' => 29.1, 'coordinate_status' => 'verified', 'published' => true]);
    DB::table('business_values')->insert(['business_id' => $id, 'kind' => 'category', 'position' => 0, 'value' => 'mechanical']);
    SearchProjection::sync($id);
}

function f5mOwned(string $id, string $owner): void
{
    f5mShop($id, ['ownership' => 'verified', 'owner_uid' => $owner]);
    f5mMember($owner, $id);
}

function f5mMember(string $uid, string $bid, string $role = 'owner', bool $active = true): void
{
    DB::table('business_memberships')->insert(['user_id' => $uid, 'business_id' => $bid, 'role' => $role, 'active' => $active]);
}

function f5mReviewBody(string $bid = 'b1', array $over = []): array
{
    return $over + ['businessId' => $bid, 'rating' => 4, 'resolved' => 'yes', 'wc' => 'no', 'text' => 'Sentetik yorum metni, yeterince uzun.'];
}

function f5mApproved($test, string $bid, array $auth, string $uid): string
{
    f5mApi($test, 'POST', '/api/reviews', $auth, f5mReviewBody($bid))->assertStatus(201);
    $id = Ids::sha([$uid, $bid]);
    DB::table('reviews')->where('id', $id)->update(['status' => 'approved', 'version' => 2]);
    DB::table('public_reviews')->insert(['business_id' => $bid, 'review_id' => $id, 'rating' => 4, 'resolved' => 'yes', 'wc' => 'no', 'text' => 'Sentetik yorum metni, yeterince uzun.']);
    DB::table('businesses')->where('id', $bid)->update(['review_count' => 1, 'review_rating_sum' => 4]);

    return $id;
}

function f5mJpeg(int $w = 40, int $h = 30): string
{
    $im = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($im, null, 90);

    return ob_get_clean();
}

function f5mFlags(): void
{
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'otosanayide.flags.review_photos' => true, 'otosanayide.flags.claim_evidence' => true, 'otosanayide.flags.business_photos' => true]);
}

function f5mImage($test, string $url, array $auth, ?string $bytes = null, string $type = 'image/jpeg')
{
    return $test->call('POST', $url, [], [], [], ['CONTENT_TYPE' => $type] + $auth, $bytes ?? f5mJpeg());
}

function f5mClaim(array $over = []): array
{
    return $over + ['name' => 'Usta Motor', 'city' => 'İstanbul', 'address' => 'Sanayi Sitesi No 12 Ümraniye', 'contactName' => 'Test Kişi', 'phone' => '05550100000', 'note' => ''];
}

function f5mProfile(): array
{
    return ['name' => 'Yeni Motor Servisi', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'address' => 'Test sokak, test adresi 10', 'phone' => '05550100000', 'website' => '', 'hours' => '', 'description' => '', 'categories' => ['mechanical'], 'services' => ['Fren'], 'brands' => ['BMW'], 'location' => null];
}

// Oturum

it('rejects revoked sessions and tokens issued before the validity cut-off', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    DB::table('auth_sessions')->where('account_id', $uid)->update(['revoked_at' => now('UTC')]);
    f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody())->assertStatus(401)->assertExactJson(['error' => 'Oturum geçersiz veya süresi dolmuş.']);
});

it('rejects a token older than tokens_valid_after', function () {
    [$uid, $auth] = f5mUser();
    DB::table('auth_accounts')->where('id', $uid)->update(['tokens_valid_after' => now('UTC')->addHour()]);
    f5mApi($this, 'GET', '/api/businesses/manage', $auth)->assertStatus(401);
});

// Yorumlar

it('creates a pending review with a deterministic id and refuses a second one', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    $r = f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody())->assertStatus(201);
    expect($r->json())->toBe(['id' => hash('sha256', json_encode([$uid, 'b1'])), 'status' => 'pending']);
    expect(DB::table('reviews')->value('status'))->toBe('pending')->and(DB::table('reviews')->value('local_policy_version'))->toBe('tr-en-1');
    expect(DB::table('review_history')->value('kind'))->toBe('submitted');
    f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody())->assertStatus(409)->assertExactJson(['error' => 'Bu işletme için yorumun zaten var.']);
});

it('refuses unpublished, sample and affiliated targets and trusts no client user', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('hidden', ['published' => false]);
    f5mShop('fake', ['sample' => true]);
    f5mOwned('mine', $uid);
    f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody('hidden'))->assertStatus(404);
    f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody('fake'))->assertStatus(404);
    f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody('mine'))->assertStatus(403)->assertExactJson(['error' => 'Yetkilisi olduğun işletmeyi puanlayamazsın.']);
    f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody('hidden', ['userId' => 'victim']))->assertStatus(400);
});

it('fences an erasing account with 409 before writing', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    DB::table('account_fences')->insert(['owner_uid' => $uid, 'job_id' => (string) Str::uuid(), 'epoch' => (string) Str::uuid(), 'state' => 'deleting', 'created_at_ms' => 1]);
    f5mApi($this, 'POST', '/api/reviews', $auth, f5mReviewBody())->assertStatus(409)->assertExactJson(['error' => 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.']);
    expect(DB::table('reviews')->count())->toBe(0);
});

it('lists public reviews without identity, with paging and average', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    f5mApproved($this, 'b1', $auth, $uid);
    $r = $this->get('/api/reviews?businessId=b1')->assertOk();
    expect($r->json('count'))->toBe(1)->and($r->json('average'))->toBe(4)->and($r->json('nextCursor'))->toBeNull()->and($r->json('reviews.0'))->not->toHaveKey('userId');
    expect($r->headers->get('Cache-Control'))->toBe('private, no-store');
    foreach (['hidden' => ['published' => false], 'fake' => ['sample' => true]] as $id => $over) {
        f5mShop($id, $over);
        $this->get('/api/reviews?businessId='.$id)->assertStatus(404)->assertExactJson(['error' => 'İşletme bulunamadı.']);
    }
});

it('hides another account own-review (IDOR) and edits with version control', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    f5mShop('b1');
    $id = f5mApproved($this, 'b1', $auth, $uid);
    f5mApi($this, 'GET', '/api/reviews/mine?businessId=b1', $other)->assertOk()->assertExactJson(['review' => null]);
    expect(f5mApi($this, 'GET', '/api/reviews/mine?businessId=b1', $auth)->json('review.canWithdraw'))->toBeTrue();
    f5mApi($this, 'PATCH', '/api/reviews/mine', $other, ['action' => 'withdraw', 'businessId' => 'b1', 'expectedVersion' => 2, 'confirmed' => true])->assertStatus(409);
    f5mApi($this, 'PATCH', '/api/reviews/mine', $auth, ['action' => 'withdraw', 'businessId' => 'b1', 'expectedVersion' => 1, 'confirmed' => true])->assertStatus(409)->assertExactJson(['error' => 'Yorum değişti. Güncel sürümü yükleyip yeniden dene.']);
    DB::table('reviews')->where('id', $id)->update(['reason' => 'Eski not', 'reviewed_by' => 'admin1']);
    f5mApi($this, 'PATCH', '/api/reviews/mine', $auth, ['action' => 'edit', 'businessId' => 'b1', 'expectedVersion' => 2, 'review' => f5mReviewBody('b1', ['rating' => 3])])->assertOk()->assertExactJson(['ok' => true, 'status' => 'pending']);
    $row = DB::table('reviews')->where('id', $id)->first();
    expect($row->status)->toBe('pending')->and($row->version)->toBe(3)->and($row->submission_version)->toBe(3)->and($row->reason)->toBeNull()->and($row->reviewed_by)->toBeNull()->and($row->rating)->toBe(3);
    expect(DB::table('public_reviews')->count())->toBe(0)->and(DB::table('businesses')->where('id', 'b1')->value('review_count'))->toBe(0);
});

it('hides a reply when its parent review changes', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    $id = f5mApproved($this, 'b1', $auth, $uid);
    DB::table('review_replies')->insert(['review_id' => $id, 'business_id' => 'b1', 'user_id' => 'owner1', 'text' => 'Teşekkür ederiz, örnek yanıt.', 'status' => 'approved', 'version' => 2]);
    f5mApi($this, 'PATCH', '/api/reviews/mine', $auth, ['action' => 'withdraw', 'businessId' => 'b1', 'expectedVersion' => 2, 'confirmed' => true])->assertOk()->assertExactJson(['ok' => true, 'status' => 'withdrawn']);
    $reply = DB::table('review_replies')->where('review_id', $id)->first();
    expect($reply->status)->toBe('hidden')->and($reply->version)->toBe(3)->and($reply->reason)->toBe('Ana yorum değişti veya geri çekildi.');
});

it('keeps a hold after hidden withdrawal and reports inconsistent publication', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    $id = f5mApproved($this, 'b1', $auth, $uid);
    DB::table('public_reviews')->delete();
    f5mApi($this, 'PATCH', '/api/reviews/mine', $auth, ['action' => 'withdraw', 'businessId' => 'b1', 'expectedVersion' => 2, 'confirmed' => true])->assertStatus(409)->assertExactJson(['error' => 'Yorum yayın kaydı tutarsız. Destek ekibine başvur.']);
    DB::table('reviews')->where('id', $id)->update(['status' => 'hidden']);
    f5mApi($this, 'PATCH', '/api/reviews/mine', $auth, ['action' => 'withdraw', 'businessId' => 'b1', 'expectedVersion' => 2, 'confirmed' => true])->assertOk();
    expect((bool) DB::table('reviews')->where('id', $id)->value('moderation_hold'))->toBeTrue();
    f5mApi($this, 'PATCH', '/api/reviews/mine', $auth, ['action' => 'edit', 'businessId' => 'b1', 'expectedVersion' => 3, 'review' => f5mReviewBody('b1')])->assertStatus(409);
});

// Basvurular

it('files reports once per version and keeps cases private', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    f5mShop('b1');
    $id = f5mApproved($this, 'b1', $auth, $uid);
    f5mApi($this, 'POST', '/api/review-cases', $auth, ['reviewId' => $id, 'kind' => 'report', 'text' => 'Sentetik rapor metni uzun.'])->assertStatus(403)->assertExactJson(['error' => 'Bu içerik için başvuru yapılamıyor.']);
    f5mApi($this, 'POST', '/api/review-cases', $other, ['reviewId' => $id, 'kind' => 'report', 'text' => 'Sentetik rapor metni uzun.'])->assertOk()->assertExactJson(['ok' => true]);
    f5mApi($this, 'POST', '/api/review-cases', $other, ['reviewId' => $id, 'kind' => 'report', 'text' => 'Sentetik rapor metni uzun.'])->assertStatus(409)->assertExactJson(['error' => 'Bu sürüm için başvurun zaten var. Başvurularım bölümünden takip et.']);
    expect(f5mApi($this, 'GET', '/api/review-cases', $auth)->json('items'))->toBe([]);
    expect(f5mApi($this, 'GET', '/api/review-cases', $other)->json('items.0.reviewId'))->toBe($id);
    f5mApi($this, 'POST', '/api/review-cases', $other, ['reviewId' => 'missing', 'kind' => 'report', 'text' => 'Sentetik rapor metni uzun.'])->assertStatus(409)->assertExactJson(['error' => 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.']);
});

it('claims: open claim conflict, reopen after closure, no Cache-Control on writes', function () {
    [$uid, $auth] = f5mUser();
    $r = f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim())->assertStatus(201);
    expect($r->json('status'))->toBe('submitted')->and($r->headers->has('Cache-Control'))->toBeFalse();
    f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim())->assertStatus(409)->assertExactJson(['error' => 'Bu işletme için açık başvurun var. Başvurularım bölümünden takip et.']);
    DB::table('claims')->update(['status' => 'rejected']);
    f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim())->assertStatus(201);
    expect(DB::table('claims')->count())->toBe(2);
    expect(f5mApi($this, 'GET', '/api/claims', $auth)->headers->get('Cache-Control'))->toBe('no-store');
});

it('claim lock UNIQUE is equivalent to the Firestore lock document', function () {
    $row = ['user_id' => 'u1', 'name' => 'N', 'city' => 'İstanbul', 'address' => 'Adres uzunlugu yeterli', 'contact_name' => 'K', 'phone' => '05550100000', 'note' => '', 'status' => 'submitted', 'lock_key' => str_repeat('a', 64)];
    DB::table('claims')->insert($row + ['id' => 'one']);
    expect(fn () => DB::table('claims')->insert($row + ['id' => 'two']))->toThrow(UniqueConstraintViolationException::class);
    DB::table('claims')->where('id', 'one')->update(['status' => 'rejected']);
    DB::table('claims')->insert($row + ['id' => 'two']);
    expect(DB::table('claims')->where('id', 'one')->value('open_lock_key'))->toBeNull()
        ->and(DB::table('claims')->where('id', 'two')->value('open_lock_key'))->toBe(str_repeat('a', 64));
});

it('businessIdentityKeys become a UNIQUE column', function () {
    f5mShop('a', ['identity_key' => str_repeat('b', 64)]);
    expect(fn () => f5mShop('c', ['identity_key' => str_repeat('b', 64)]))->toThrow(UniqueConstraintViolationException::class);
});

it('the media and external ledgers allow one active operation per object', function () {
    $op = ['actor_uid' => 'u', 'resource_kind' => 'review', 'resource_id' => (string) Str::uuid(), 'path' => 'review-photos/x/full.webp', 'method' => 'save', 'binding' => '{}', 'state' => 'open', 'version' => 1, 'created_at_ms' => 1, 'updated_at_ms' => 1];
    DB::table('account_media_operations')->insert($op + ['id' => (string) Str::uuid()]);
    expect(fn () => DB::table('account_media_operations')->insert($op + ['id' => (string) Str::uuid()]))->toThrow(UniqueConstraintViolationException::class);
    $ext = ['kind' => 'places', 'binding_digest' => str_repeat('a', 64), 'resource_key' => str_repeat('b', 64), 'source_digest' => str_repeat('c', 64), 'state' => 'prepared', 'version' => 1, 'created_at_ms' => 1, 'updated_at_ms' => 1];
    DB::table('account_external_operations')->insert($ext + ['id' => (string) Str::uuid()]);
    expect(fn () => DB::table('account_external_operations')->insert($ext + ['id' => (string) Str::uuid()]))->toThrow(UniqueConstraintViolationException::class);
});

it('claims: target rules and claimant-only reply', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    f5mShop('free');
    f5mOwned('taken', 'someone');
    f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim(['businessId' => 'nope']))->assertStatus(404)->assertExactJson(['error' => 'Başvuruya uygun işletme kaydı bulunamadı.']);
    f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim(['businessId' => 'taken']))->assertStatus(409)->assertExactJson(['error' => 'Bu işletmenin sahipliği ayrıca incelenmeli.']);
    $id = f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim(['businessId' => 'free']))->assertStatus(201)->json('id');
    expect(f5mApi($this, 'GET', '/api/claims', $other)->json('claims'))->toBe([]);
    f5mApi($this, 'PATCH', '/api/claims', $auth, ['claimId' => $id, 'message' => 'İstenen ek açıklama.'])->assertStatus(409);
    DB::table('claims')->where('id', $id)->update(['status' => 'needs_information']);
    f5mApi($this, 'PATCH', '/api/claims', $other, ['claimId' => $id, 'message' => 'İstenen ek açıklama.'])->assertStatus(409);
    $ok = f5mApi($this, 'PATCH', '/api/claims', $auth, ['claimId' => $id, 'message' => 'İstenen ek açıklama.'])->assertOk()->assertExactJson(['ok' => true, 'status' => 'submitted']);
    expect($ok->headers->has('Cache-Control'))->toBeFalse()->and(DB::table('claims')->where('id', $id)->value('evidence_revision'))->toBe(1);
});

// Belge

it('evidence: closed flag, upload, private access, integrity and withdraw', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    [$adminId, $admin] = f5mUser('a@example.com', true, true);
    $claim = f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim())->json('id');
    f5mImage($this, '/api/claims/evidence?claimId='.$claim.'&kind=workplace', $auth)->assertStatus(503)->assertExactJson(['error' => 'Güvenli belge yükleme henüz açılmadı.']);
    f5mFlags();
    f5mImage($this, '/api/claims/evidence?claimId=nope&kind=workplace', $auth)->assertStatus(404);
    f5mImage($this, '/api/claims/evidence?claimId='.$claim.'&kind=workplace', $auth, 'x', 'text/plain')->assertStatus(415);
    $up = f5mImage($this, '/api/claims/evidence?claimId='.$claim.'&kind=workplace', $auth)->assertStatus(201);
    $id = $up->json('id');
    expect($up->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($up->json('status'))->toBe('ready');
    $c = DB::table('claims')->where('id', $claim)->first();
    expect($c->evidence_count)->toBe(1)->and($c->pending_evidence_uploads)->toBe(0)->and($c->evidence_revision)->toBe(1)->and(DB::table('account_media_operations')->value('state'))->toBe('closed');
    f5mApi($this, 'GET', '/api/claims/evidence?claimId='.$claim, $other)->assertStatus(404)->assertExactJson(['error' => 'Başvuru bulunamadı.']);
    expect(f5mApi($this, 'GET', '/api/claims/evidence?claimId='.$claim, $auth)->json('items.0.kind'))->toBe('workplace');
    $file = f5mApi($this, 'GET', '/api/claims/evidence?claimId='.$claim.'&id='.$id, $auth)->assertOk();
    expect($file->headers->get('Content-Type'))->toBe('image/webp')->and($file->headers->get('Content-Disposition'))->toBe('inline; filename="belge.webp"');
    expect(DB::table('claim_evidence_views')->count())->toBe(0);
    f5mApi($this, 'GET', '/api/claims/evidence?claimId='.$claim.'&id='.$id, $admin)->assertOk();
    expect(DB::table('claim_evidence_views')->value('reviewer_id'))->toBe($adminId);
    Storage::disk('local')->put(MediaStore::evidencePath($claim, $id), 'tampered');
    f5mApi($this, 'GET', '/api/claims/evidence?claimId='.$claim.'&id='.$id, $auth)->assertStatus(409)->assertExactJson(['error' => 'Belge bütünlüğü doğrulanamadı.']);
    f5mApi($this, 'PATCH', '/api/claims/evidence', $other, ['claimId' => $claim, 'id' => $id, 'action' => 'withdraw'])->assertStatus(409);
    f5mApi($this, 'PATCH', '/api/claims/evidence', $auth, ['claimId' => $claim, 'id' => $id, 'action' => 'withdraw'])->assertOk()->assertExactJson(['ok' => true]);
    expect(DB::table('claims')->where('id', $claim)->value('evidence_count'))->toBe(0);
});

it('evidence: bad images release counters and limits apply', function () {
    [$uid, $auth] = f5mUser();
    $claim = f5mApi($this, 'POST', '/api/claims', $auth, f5mClaim())->json('id');
    f5mFlags();
    f5mImage($this, '/api/claims/evidence?claimId='.$claim.'&kind=workplace', $auth, 'not an image')->assertStatus(400)->assertExactJson(['error' => 'Belge fotoğrafı okunamadı veya boyut sınırını aşıyor. JPEG, PNG veya WebP sayfasını yeniden seç.']);
    $c = DB::table('claims')->where('id', $claim)->first();
    expect($c->evidence_count)->toBe(0)->and($c->pending_evidence_uploads)->toBe(0)->and($c->total_evidence_uploads)->toBe(1)->and(DB::table('claim_evidence')->value('status'))->toBe('failed');
    DB::table('claims')->where('id', $claim)->update(['evidence_count' => 5]);
    f5mImage($this, '/api/claims/evidence?claimId='.$claim.'&kind=workplace', $auth)->assertStatus(409)->assertExactJson(['error' => 'Başvuru başına en fazla beş belge sayfası eklenebilir.']);
    DB::table('claims')->where('id', $claim)->update(['evidence_count' => 0, 'total_evidence_uploads' => 20]);
    f5mImage($this, '/api/claims/evidence?claimId='.$claim.'&kind=workplace', $auth)->assertStatus(409)->assertExactJson(['error' => 'Belge deneme sınırına ulaşıldı. Destek ekibine başvur.']);
});

// Yorum fotograflari

it('review photos: closed, upload limits, privacy and withdraw', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    f5mShop('b1');
    $review = f5mApproved($this, 'b1', $auth, $uid);
    f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $auth)->assertStatus(503)->assertExactJson(['error' => 'Yorum fotoğrafı yükleme henüz açılmadı.']);
    f5mFlags();
    f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $other)->assertStatus(403)->assertExactJson(['error' => 'Bu yoruma fotoğraf ekleyemezsin.']);
    f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes&version=9', $auth)->assertStatus(409)->assertExactJson(['error' => 'Yorum değişti. Durumu yenile.']);
    $up = f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $auth)->assertStatus(201);
    $id = $up->json('id');
    expect(DB::table('review_photos')->where('id', $id)->value('status'))->toBe('pending')->and(DB::table('reviews')->where('id', $review)->value('photo_attempts'))->toBe(1);
    expect(f5mApi($this, 'GET', '/api/review-photos?reviewId='.$review, $auth)->json('items.0.id'))->toBe($id);
    f5mApi($this, 'GET', '/api/review-photos?reviewId='.$review, $other)->assertStatus(404)->assertExactJson(['error' => 'Yorum bulunamadı.']);
    $this->call('GET', '/api/review-photos/media?id='.$id, [], [], [], ['HTTP_ACCEPT_LANGUAGE' => ''])->assertStatus(401);
    f5mApi($this, 'GET', '/api/review-photos/media?id='.$id, $other)->assertStatus(404);
    $own = f5mApi($this, 'GET', '/api/review-photos/media?id='.$id.'&size=thumbnail', $auth)->assertOk();
    expect($own->headers->get('Content-Type'))->toBe('image/webp')->and($own->headers->get('Cache-Control'))->toBe('private, no-store');
    f5mApi($this, 'PATCH', '/api/review-photos', $other, ['id' => $id, 'version' => 1])->assertStatus(409);
    f5mApi($this, 'PATCH', '/api/review-photos', $auth, ['id' => $id, 'version' => 2])->assertStatus(409)->assertExactJson(['error' => 'Fotoğraf değişti. Listeyi yenile.']);
    f5mApi($this, 'PATCH', '/api/review-photos', $auth, ['id' => $id, 'version' => 1])->assertOk()->assertExactJson(['ok' => true]);
    expect(DB::table('review_photos')->where('id', $id)->value('status'))->toBe('withdrawn')->and(DB::table('review_photo_refs')->count())->toBe(0);
});

it('review photo reservation enforces three slots and twenty attempts', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    $review = f5mApproved($this, 'b1', $auth, $uid);
    f5mFlags();
    foreach (range(1, 3) as $i) {
        f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $auth)->assertStatus(201);
    }
    f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $auth)->assertStatus(409)->assertExactJson(['error' => 'Fotoğraf sayısı veya yükleme deneme sınırına ulaşıldı.']);
    DB::table('review_photo_refs')->delete();
    DB::table('reviews')->where('id', $review)->update(['photo_attempts' => 20]);
    f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $auth)->assertStatus(409);
});

it('a failed provider save stays uncertain and blocks the object', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    $review = f5mApproved($this, 'b1', $auth, $uid);
    f5mFlags();
    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('network'));
    Storage::shouldReceive('disk')->andReturn($disk);
    f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $auth)->assertStatus(500);
    expect(DB::table('account_media_operations')->value('state'))->toBe('uncertain')->and(DB::table('account_media_barriers')->where('owner_uid', $uid)->value('uncertain'))->toBe(1);
});

it('oversize upload is 413 and marks the reservation failed', function () {
    [$uid, $auth] = f5mUser();
    f5mShop('b1');
    $review = f5mApproved($this, 'b1', $auth, $uid);
    f5mFlags();
    f5mImage($this, '/api/review-photos?reviewId='.$review.'&rights=yes', $auth, str_repeat('a', 2500001))->assertStatus(413)->assertExactJson(['error' => 'Fotoğraf boyut sınırını aşıyor.']);
    expect(DB::table('review_photos')->value('status'))->toBe('failed')->and(DB::table('review_photo_refs')->count())->toBe(0);
});

// Isletme

it('manage: IDOR, optimistic concurrency and pending-only writes', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    f5mOwned('b1', $uid);
    f5mApi($this, 'GET', '/api/businesses/manage?businessId=b1', $other)->assertStatus(403)->assertExactJson(['error' => 'Bu işletmenin profilini düzenleyemezsin.']);
    f5mApi($this, 'PATCH', '/api/businesses/manage', $other, ['businessId' => 'b1', 'expectedVersion' => 0, 'expectedRevisionId' => null, 'profile' => f5mProfile()])->assertStatus(403);
    $r = f5mApi($this, 'PATCH', '/api/businesses/manage', $auth, ['businessId' => 'b1', 'expectedVersion' => 0, 'expectedRevisionId' => null, 'profile' => f5mProfile()])->assertOk();
    $revision = $r->json('revisionId');
    expect($r->json('status'))->toBe('pending')->and(DB::table('businesses')->where('id', 'b1')->value('name'))->toBe('Usta Motor');
    f5mApi($this, 'PATCH', '/api/businesses/manage', $auth, ['businessId' => 'b1', 'expectedVersion' => 0, 'expectedRevisionId' => null, 'profile' => f5mProfile()])->assertStatus(409)->assertExactJson(['error' => 'Bekleyen değişiklik güncellendi. Sayfayı yenile.']);
    f5mApi($this, 'PATCH', '/api/businesses/manage', $auth, ['businessId' => 'b1', 'expectedVersion' => 9, 'expectedRevisionId' => $revision, 'profile' => f5mProfile()])->assertStatus(409)->assertExactJson(['error' => 'Profil değişti. Güncel bilgileri yükleyip yeniden dene.']);
    $one = f5mApi($this, 'GET', '/api/businesses/manage?businessId=b1', $auth)->assertOk();
    expect($one->json('businesses.0.change.revisionId'))->toBe($revision)->and($one->json('businesses.0.change.profile.name'))->toBe('Yeni Motor Servisi');
    expect(f5mApi($this, 'GET', '/api/businesses/manage', $other)->json('businesses'))->toBe([]);
    expect(DB::table('business_history')->where('business_id', 'b1')->where('kind', 'profile_submitted')->count())->toBe(1);
});

it('business photos: owner only, upload, cover rules, public list and withdraw', function () {
    [$uid, $auth] = f5mUser();
    [$staffId, $staff] = f5mUser('s@example.com');
    f5mOwned('b1', $uid);
    f5mMember($staffId, 'b1', 'staff');
    f5mFlags();
    f5mApi($this, 'GET', '/api/businesses/photos?businessId=b1', $staff)->assertStatus(403)->assertExactJson(['error' => 'İşletme fotoğrafları için sahip yetkisi gerekli.']);
    $id = (string) Str::uuid();
    $url = '/api/businesses/photos?businessId=b1&rights=yes&requestId='.$id;
    f5mImage($this, $url, $staff)->assertStatus(403);
    f5mImage($this, $url, $auth)->assertStatus(201);
    f5mImage($this, $url, $auth)->assertStatus(409)->assertExactJson(['error' => 'Bu yükleme kimliği kullanıldı. Galeriyi yenile.']);
    $list = f5mApi($this, 'GET', '/api/businesses/photos?businessId=b1', $auth)->assertOk();
    expect($list->json('items.0.status'))->toBe('pending')->and($list->json('items.0.canOpen'))->toBeTrue();
    expect($this->get('/api/businesses/photos/public?businessId=b1')->json('ids'))->toBe([]);
    f5mApi($this, 'PATCH', '/api/businesses/photos', $auth, ['businessId' => 'b1', 'id' => $id, 'version' => 1, 'action' => 'cover'])->assertStatus(409)->assertExactJson(['error' => 'Yalnız onaylı güncel fotoğraf kapak olabilir.']);
    DB::table('business_photos')->where('id', $id)->update(['status' => 'approved']);
    DB::table('business_public_photos')->insert(['business_id' => 'b1', 'position' => 0, 'photo_id' => $id]);
    f5mApi($this, 'PATCH', '/api/businesses/photos', $auth, ['businessId' => 'b1', 'id' => $id, 'version' => 1, 'action' => 'cover'])->assertOk();
    expect(DB::table('businesses')->where('id', 'b1')->value('business_cover_photo_id'))->toBe($id);
    expect($this->get('/api/businesses/photos/public?businessId=b1')->json())->toBe(['businessId' => 'b1', 'ids' => [$id], 'coverId' => $id]);
    $media = $this->get('/api/businesses/photos/media?businessId=b1&id='.$id)->assertOk();
    expect($media->headers->get('Content-Disposition'))->toBe('inline; filename="business-photo.webp"');
    f5mApi($this, 'PATCH', '/api/businesses/photos', $staff, ['businessId' => 'b1', 'id' => $id, 'version' => 1, 'action' => 'withdraw'])->assertStatus(403);
    f5mApi($this, 'PATCH', '/api/businesses/photos', $auth, ['businessId' => 'b1', 'id' => $id, 'version' => 1, 'action' => 'withdraw'])->assertOk();
    expect(DB::table('businesses')->where('id', 'b1')->value('business_cover_photo_id'))->toBeNull()->and(DB::table('business_public_photos')->count())->toBe(0)->and(DB::table('business_photo_slots')->count())->toBe(0);
});

it('replies: only verified members, not the author, with versioning', function () {
    [$uid, $auth] = f5mUser();
    [$ownerId, $owner] = f5mUser('o@example.com');
    [, $stranger] = f5mUser('x@example.com');
    f5mOwned('b1', $ownerId);
    $review = f5mApproved($this, 'b1', $auth, $uid);
    $body = ['businessId' => 'b1', 'reviewId' => $review, 'expectedVersion' => 0, 'text' => 'Teşekkür ederiz, örnek yanıt.'];
    f5mApi($this, 'POST', '/api/businesses/replies', $stranger, $body)->assertStatus(403)->assertExactJson(['error' => 'Bu işletme adına yanıt veremezsin.']);
    f5mApi($this, 'GET', '/api/businesses/replies?businessId=b1', $stranger)->assertStatus(403);
    f5mApi($this, 'POST', '/api/businesses/replies', $owner, $body)->assertOk()->assertExactJson(['ok' => true]);
    f5mApi($this, 'POST', '/api/businesses/replies', $owner, $body)->assertStatus(409);
    expect(f5mApi($this, 'GET', '/api/businesses/replies?businessId=b1', $owner)->json('reviews.0.replyDraft.status'))->toBe('pending');
    $withdraw = ['businessId' => 'b1', 'reviewId' => $review, 'expectedVersion' => 1];
    f5mApi($this, 'PATCH', '/api/businesses/replies', $owner, ['expectedVersion' => 5] + $withdraw)->assertStatus(409)->assertExactJson(['error' => 'Yanıt değişti. Listeyi yenile.']);
    DB::table('public_reviews')->update(['reply_text' => 'Teşekkür ederiz, örnek yanıt.']);
    f5mApi($this, 'PATCH', '/api/businesses/replies', $owner, $withdraw)->assertOk();
    expect(DB::table('review_replies')->value('status'))->toBe('hidden')->and(DB::table('public_reviews')->value('reply_text'))->toBeNull();
});

it('activation: owner request, independent approval, role and stale checks', function () {
    [$uid, $auth] = f5mUser();
    [$adminId, $admin] = f5mUser('a@example.com', true, true);
    [, $other] = f5mUser('x@example.com');
    f5mOwned('b1', $uid);
    $request = ['businessId' => 'b1', 'version' => 0, 'action' => 'request', 'features' => ['quotes' => true, 'appointments' => false], 'monitorInbox' => true, 'capacityReady' => true];
    f5mApi($this, 'GET', '/api/businesses/activation?businessId=b1', $other)->assertStatus(403)->assertExactJson(['error' => 'Yetkiniz yok.']);
    f5mApi($this, 'PATCH', '/api/businesses/activation', $other, $request)->assertStatus(403)->assertExactJson(['error' => 'Yetkiniz yok.']);
    f5mApi($this, 'PATCH', '/api/businesses/activation', $auth, $request)->assertOk()->assertExactJson(['ok' => true]);
    f5mApi($this, 'PATCH', '/api/businesses/activation', $auth, $request)->assertStatus(409)->assertExactJson(['error' => 'Aktivasyon durumu değişti veya koşullar sağlanmıyor. Yenileyip tekrar dene.']);
    expect((bool) DB::table('businesses')->where('id', 'b1')->value('feature_quotes'))->toBeFalse();
    expect(f5mApi($this, 'GET', '/api/businesses/activation?businessId=b1', $auth)->json('request.status'))->toBe('pending');
    expect(f5mApi($this, 'GET', '/api/businesses/activation?queue=true', $admin)->json('items.0.id'))->toBe('b1');
    f5mApi($this, 'PATCH', '/api/businesses/activation', $auth, ['businessId' => 'b1', 'version' => 1, 'action' => 'approve', 'reason' => 'Readiness reviewed'])->assertStatus(403);
    f5mApi($this, 'PATCH', '/api/businesses/activation', $admin, ['businessId' => 'b1', 'version' => 1, 'action' => 'approve', 'reason' => 'Readiness reviewed'])->assertOk();
    $b = DB::table('businesses')->where('id', 'b1')->first();
    expect((bool) $b->owner_ready)->toBeTrue()->and((bool) $b->feature_quotes)->toBeTrue()->and($b->activation_version)->toBe(2);
    expect(DB::table('feature_activations')->value('status'))->toBe('approved')->and(DB::table('business_history')->where('kind', 'feature_activation')->count())->toBe(2);
    expect($adminId)->not->toBe($uid);
});

it('activation: a pause without a prior applicant blocks further writes like the source', function () {
    [$uid, $auth] = f5mUser();
    f5mOwned('b1', $uid);
    f5mApi($this, 'PATCH', '/api/businesses/activation', $auth, ['businessId' => 'b1', 'version' => 0, 'action' => 'pause', 'reason' => 'TEST pause reason'])->assertOk();
    f5mApi($this, 'PATCH', '/api/businesses/activation', $auth, ['businessId' => 'b1', 'version' => 1, 'action' => 'pause', 'reason' => 'TEST pause reason'])->assertStatus(409)->assertExactJson(['error' => 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.']);
});

it('console lists only own canonical memberships with cursor paging', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    foreach (range(1, 21) as $i) {
        f5mOwned('b'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), $uid);
    }
    $first = f5mApi($this, 'GET', '/api/businesses/console', $auth)->assertOk();
    expect($first->json('businesses'))->toHaveCount(20)->and($first->json('nextCursor'))->toBe($uid.'_b020');
    expect(f5mApi($this, 'GET', '/api/businesses/console?after='.$uid.'_b020', $auth)->json('businesses.0.id'))->toBe('b021');
    expect(f5mApi($this, 'GET', '/api/businesses/console', $other)->json('businesses'))->toBe([]);
});

it('places: owner only, keeps an uncertain call from being resent', function () {
    [$uid, $auth] = f5mUser();
    [, $other] = f5mUser('o@example.com');
    f5mOwned('b1', $uid);
    config(['otosanayide.flags.google_places' => true, 'otosanayide.google_places_api_key' => 'synthetic-key']);
    $body = ['businessId' => 'b1', 'query' => 'Sanayi Sitesi', 'locale' => 'tr'];
    f5mApi($this, 'POST', '/api/businesses/places', $other, $body)->assertStatus(403)->assertExactJson(['error' => 'Bu işletme için yetkiniz yok.']);
    Http::fake(['places.googleapis.com/*' => Http::response(['places' => []], 200, ['Content-Type' => 'application/json'])]);
    f5mApi($this, 'POST', '/api/businesses/places', $auth, $body)->assertOk()->assertExactJson(['places' => [], 'source' => 'google_places', 'verification' => 'candidate']);
    expect(DB::table('account_external_operations')->value('state'))->toBe('settled');
    Http::fake(['places.googleapis.com/*' => Http::failedConnection()]);
    $body['query'] = 'Sanayi Sitesi 2';
    f5mApi($this, 'POST', '/api/businesses/places', $auth, $body)->assertStatus(500);
    expect(DB::table('account_external_operations')->where('state', 'uncertain')->count())->toBe(1);
    f5mApi($this, 'POST', '/api/businesses/places', $auth, $body)->assertStatus(500);
    expect(DB::table('account_external_operations')->count())->toBe(2);
});
