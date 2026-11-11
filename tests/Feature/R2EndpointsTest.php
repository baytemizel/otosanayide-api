<?php

use App\Domain\Catalogue\OpeningHours;
use App\Domain\Catalogue\ReviewSummary;
use App\Domain\Catalogue\SearchCursor;
use App\Domain\Catalogue\SearchFilter;
use App\Domain\User\AppointmentCapacity;
use App\Support\Query;
use App\Support\ZodError;
use Illuminate\Support\Facades\DB;

// 2. tur endpoint talepleri. Surec ici SQLite (tests/Support/R2Db.php); MySQL kabulu DEGILDIR.

const R2_WEB = ['HTTP_ORIGIN' => 'https://otosanayide.test'];

beforeEach(fn () => r2Db());

function r2Hours(): string
{
    $week = array_map(fn (int $d) => ['day' => $d, 'intervals' => [['open' => '09:00', 'close' => '12:00']]], range(1, 7));

    return (string) json_encode(['timezone' => 'Europe/Istanbul', 'week' => $week, 'exceptions' => []]);
}

it('web-09: stores preferences, records product news consent and blocks reminder e-mail', function () {
    $u = r2User('u1');
    r2('GET', '/api/me/preferences', $u)->assertOk()->assertJsonPath('version', 0)->assertJsonPath('productNews.enabled', false)->assertJsonPath('reminderEmail', true);
    r2('PATCH', '/api/me/preferences', $u, '{"expectedVersion":0,"changes":{"productNews":true}}')->assertStatus(400);
    r2('PATCH', '/api/me/preferences', $u, '{"expectedVersion":0,"changes":{"productNews":true,"productNewsTextVersion":"product-news-2026-11"}}')->assertStatus(400); // kaynak yok
    $r = r2('PATCH', '/api/me/preferences', $u + R2_WEB, '{"expectedVersion":0,"changes":{"productNews":true,"productNewsTextVersion":"product-news-2026-11","reminderEmail":false}}')->assertOk();
    expect($r->json('productNews'))->toMatchArray(['enabled' => true, 'source' => 'web', 'textVersion' => 'product-news-2026-11'])->and($r->json('version'))->toBe(1);
    expect(DB::table('user_consent_events')->count())->toBe(1);
    r2('PATCH', '/api/me/preferences', $u + R2_WEB, '{"expectedVersion":0,"changes":{"reminderEmail":true}}')->assertStatus(409);
    expect(\App\Domain\User\Preferences::reminderEmailAllowed('u1'))->toBeFalse();
    $unverified = r2User('u2', false);
    r2('PATCH', '/api/me/preferences', $unverified + R2_WEB, '{"expectedVersion":0,"changes":{"requestUpdatesEmail":true}}')->assertStatus(403);
});

it('web-12: accepts only uncovered provinces for verified accounts', function () {
    $u = r2User('u1');
    r2('POST', '/api/coverage/waitlist', $u, '{"city":"İstanbul"}')->assertStatus(400);
    r2('POST', '/api/coverage/waitlist', $u, '{"city":"Atlantis"}')->assertStatus(400);
    r2('POST', '/api/coverage/waitlist', $u, '{"city":"Bayburt"}')->assertStatus(201)->assertJsonPath('city', 'Bayburt');
    r2('POST', '/api/coverage/waitlist', $u, '{"city":"Bayburt"}')->assertOk();
    r2('GET', '/api/coverage/waitlist', $u)->assertOk()->assertJsonCount(1, 'items');
    r2('DELETE', '/api/coverage/waitlist?city='.rawurlencode('Bayburt'), $u)->assertStatus(204);
    r2('GET', '/api/coverage/waitlist', r2User('u2', false))->assertStatus(403);
});

it('web-13: manages lists with limits and versions without touching flat favourites', function () {
    $u = r2User('u1');
    r2Business('b1');
    r2Business('b2');
    r2Business('b3', null, ['published' => false]);
    $op = '11111111-1111-4111-8111-111111111111';
    $created = r2('POST', '/api/me/lists', $u, json_encode(['name' => 'Yaz', 'kind' => 'comparison', 'businessIds' => ['b1'], 'operationId' => $op]))->assertStatus(201);
    r2('POST', '/api/me/lists', $u, json_encode(['name' => 'Yaz', 'kind' => 'comparison', 'businessIds' => ['b1'], 'operationId' => $op]))->assertOk();
    r2('POST', '/api/me/lists', $u, json_encode(['name' => 'X', 'kind' => 'list', 'businessIds' => ['b3'], 'operationId' => '22222222-2222-4222-8222-222222222222']))->assertStatus(400);
    $id = $created->json('id');
    r2('PATCH', '/api/me/lists', $u, json_encode(['id' => $id, 'expectedVersion' => 1, 'add' => ['b2']]))->assertOk()->assertJsonPath('businessIds', ['b1', 'b2']);
    r2('PATCH', '/api/me/lists', $u, json_encode(['id' => $id, 'expectedVersion' => 1, 'name' => 'Z']))->assertStatus(409);
    DB::table('businesses')->where('id', 'b2')->update(['published' => false]);
    r2('GET', '/api/me/lists', $u)->assertOk()->assertJsonPath('items.0.unavailableIds', ['b2']);
    r2('DELETE', '/api/me/lists?id='.$id, r2User('u2'))->assertStatus(404);
    r2('DELETE', '/api/me/lists?id='.$id, $u)->assertStatus(204);
    expect(DB::table('user_favorites')->count())->toBe(0);
});

it('web-10: queues a manual export request and lets an admin process it', function () {
    config(['otosanayide.flags.data_requests' => true]);
    $u = r2User('u1');
    $admin = r2User('a1', true, true);
    $op = '33333333-3333-4333-8333-333333333333';
    $r = r2('POST', '/api/me/privacy/exports', $u, json_encode(['operationId' => $op]))->assertStatus(202);
    expect($r->json('preparation'))->toBe('manual')->and($r->json('request.status'))->toBe('submitted');
    r2('POST', '/api/me/privacy/exports', $u, json_encode(['operationId' => '44444444-4444-4444-8444-444444444444']))->assertStatus(409);
    r2('GET', '/api/admin/privacy?kind=export', $admin)->assertOk()->assertJsonPath('items.0.ownerUid', 'u1');
    r2('POST', '/api/admin/privacy', $admin, json_encode(['kind' => 'export', 'requestId' => $op, 'expectedVersion' => 1, 'action' => 'deliver']))->assertStatus(409);
    r2('POST', '/api/admin/privacy', $admin, json_encode(['kind' => 'export', 'requestId' => $op, 'expectedVersion' => 1, 'action' => 'start']))->assertOk()->assertJsonPath('request.status', 'in_progress');
    r2('GET', '/api/me/privacy/exports', $u)->assertOk()->assertJsonPath('items.0.status', 'in_progress');
    config(['otosanayide.flags.data_requests' => false]);
    r2('GET', '/api/me/privacy/exports', $u)->assertStatus(503);
});

it('web-04: takes a report without changing the profile and lets an admin close it', function () {
    $u = r2User('u1');
    $admin = r2User('a1', true, true);
    r2Business('b1');
    $op = '55555555-5555-4555-8555-555555555555';
    r2('POST', '/api/businesses/b1/reports', $u, json_encode(['reason' => 'other', 'note' => 'kısa', 'operationId' => $op]))->assertStatus(400);
    r2('POST', '/api/businesses/b1/reports', $u, json_encode(['reason' => 'phone', 'note' => 'Doğru numara 05321234567', 'operationId' => $op]))->assertStatus(400);
    r2('POST', '/api/businesses/b1/reports', $u, json_encode(['reason' => 'phone', 'note' => 'Numara artık çalışmıyor.', 'operationId' => $op]))->assertStatus(201)->assertJsonPath('status', 'submitted');
    r2('POST', '/api/businesses/b1/reports', $u, json_encode(['reason' => 'closed', 'operationId' => '66666666-6666-4666-8666-666666666666']))->assertStatus(409);
    r2('POST', '/api/businesses/zz/reports', $u, json_encode(['reason' => 'closed', 'operationId' => '77777777-7777-4777-8777-777777777777']))->assertStatus(404);
    $q = r2('GET', '/api/admin/business-reports', $admin)->assertOk();
    expect($q->json('items.0'))->not->toHaveKey('reporterUid')->and($q->json('items.0.businessName'))->toBe('Sentetik Servis b1');
    r2('GET', '/api/admin/business-reports', $u)->assertStatus(403);
    r2('PATCH', '/api/admin/business-reports', $admin, json_encode(['id' => $op, 'expectedVersion' => 1, 'decision' => 'resolved', 'resolution' => 'Telefon katalogda güncellendi.']))->assertOk();
    expect(DB::table('businesses')->where('id', 'b1')->value('phone'))->toBe('');
});

it('web-16: summarises queues and lists flags without values to change', function () {
    $admin = r2User('a1', true, true);
    DB::table('claims')->insert([['id' => 'c1', 'status' => 'submitted'], ['id' => 'c2', 'status' => 'approved']]);
    r2('GET', '/api/admin/overview', $admin)->assertOk()->assertJsonPath('queues.claims', 1)->assertJsonPath('queues.businessReports', 0);
    $f = r2('GET', '/api/admin/flags', $admin)->assertOk();
    expect($f->json('writable'))->toBeFalse()->and(collect($f->json('flags'))->pluck('name'))->toContain('PUSH_DELIVERY_ENABLED')
        ->and((string) $f->getContent())->not->toContain('api_key')->not->toContain('secret');
    r2('GET', '/api/admin/overview', r2User('u1'))->assertStatus(403);
    // Portal origin'i /api/admin altinda CORS almaz.
    expect(r2('GET', '/api/admin/overview', $admin + ['HTTP_ORIGIN' => 'https://portal.otosanayide.test'])->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('portal-01: owner invites staff, only the invited verified address accepts, owner suspends', function () {
    $owner = r2User('o1');
    r2Business('b1', 'o1');
    $staff = r2User('s1', true, false, 'calisan@example.com');
    $other = r2User('x1', true, false, 'baska@example.com');
    r2('POST', '/api/businesses/team/invitations', $staff, '{"businessId":"b1","email":"yeni@example.com"}')->assertStatus(403);
    r2('POST', '/api/businesses/team/invitations', $owner + ['HTTP_ORIGIN' => 'https://portal.otosanayide.test'], '{"businessId":"b1","email":"Calisan@Example.com"}')->assertStatus(201)->assertJsonPath('role', 'staff');
    $mail = collect(app('mail.manager')->mailer()->getSymfonyTransport()->messages())->last()->getOriginalMessage()->getTextBody();
    preg_match('/token=([A-Za-z0-9_-]+)/', $mail, $m);
    expect($mail)->toContain('https://portal.otosanayide.test/ekip/davet?token=');
    r2('POST', '/api/businesses/team/invitations/accept', $other, json_encode(['token' => $m[1]]))->assertStatus(400);
    r2('POST', '/api/businesses/team/invitations/accept', $staff, json_encode(['token' => $m[1]]))->assertOk()->assertExactJson(['businessId' => 'b1', 'role' => 'staff']);
    r2('POST', '/api/businesses/team/invitations/accept', $staff, json_encode(['token' => $m[1]]))->assertStatus(400);
    $team = r2('GET', '/api/businesses/team?businessId=b1', $staff)->assertOk();
    expect($team->json('invitations'))->toBe([])->and(collect($team->json('members'))->firstWhere('memberId', 'o1')['email'])->toBe('o***@example.com');
    r2('PATCH', '/api/businesses/team', $staff, '{"businessId":"b1","memberId":"o1","expectedVersion":1,"action":"suspend"}')->assertStatus(403);
    r2('PATCH', '/api/businesses/team', $owner, '{"businessId":"b1","memberId":"o1","expectedVersion":1,"action":"suspend"}')->assertStatus(409);
    r2('PATCH', '/api/businesses/team', $owner, '{"businessId":"b1","memberId":"s1","expectedVersion":1,"action":"suspend"}')->assertOk();
    r2('GET', '/api/businesses/team?businessId=b1', $staff)->assertStatus(403);
    expect(DB::table('business_team_audit')->pluck('action')->all())->toBe(['invite', 'accept', 'suspend']);
});

it('portal-02: summarises existing data only', function () {
    $owner = r2User('o1');
    r2Business('b1', 'o1');
    DB::table('user_favorites')->insert([['user_id' => 'x', 'business_id' => 'b1'], ['user_id' => 'y', 'business_id' => 'b1']]);
    DB::table('public_reviews')->insert([['business_id' => 'b1', 'review_id' => 'r1', 'rating' => 5, 'reply_text' => null], ['business_id' => 'b1', 'review_id' => 'r2', 'rating' => 4, 'reply_text' => 'Teşekkürler']]);
    DB::table('requests')->insert(['id' => 'q1', 'business_id' => 'b1', 'business_name' => 'x', 'user_id' => 'c1', 'kind' => 'quotes', 'service' => 'Fren', 'vehicle' => 'Araç', 'message' => 'Sentetik mesaj metni', 'status' => 'requested', 'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s').'.000']);
    $r = r2('GET', '/api/businesses/insights?businessId=b1&days=7', $owner)->assertOk();
    expect($r->json('favorites.total'))->toBe(2)->and($r->json('reviews'))->toBe(['count' => 2, 'average' => 4.5, 'awaitingReply' => 1])
        ->and($r->json('requests.total'))->toBe(1)->and($r->json('requests.rows'))->toHaveCount(7)->and($r->json('notTracked'))->toContain('profileViews');
    r2('GET', '/api/businesses/insights?businessId=b1&days=90', $owner)->assertStatus(400);
    r2('GET', '/api/businesses/insights?businessId=b1', r2User('u9'))->assertStatus(403);
});

it('portal-03: availability is empty without hours or settings and honours closures and capacity', function () {
    $owner = r2User('o1');
    $customer = r2User('c1');
    r2Business('b1', 'o1');
    $tomorrow = AppointmentCapacity::dayOffset(1);
    r2('GET', "/api/businesses/b1/availability?from={$tomorrow}&days=1")->assertOk()->assertJsonPath('days', []);
    r2('PATCH', '/api/businesses/capacity', $owner, '{"businessId":"b1","expectedVersion":0,"slotMinutes":60,"capacity":1,"approval":"auto_offer","accepting":true}')->assertOk()->assertJsonPath('configured', true);
    r2('GET', "/api/businesses/b1/availability?from={$tomorrow}&days=1")->assertOk()->assertJsonPath('days', []); // saat yok
    DB::table('businesses')->where('id', 'b1')->update(['opening_hours' => r2Hours()]);
    r2('GET', "/api/businesses/b1/availability?from={$tomorrow}&days=1")->assertOk()->assertJsonPath('days.0.slots', ['09:00', '10:00', '11:00']);

    $body = fn (string $slot, string $key) => json_encode(['businessId' => 'b1', 'kind' => 'appointments', 'service' => 'Bakım', 'vehicle' => 'Sentetik araç', 'message' => 'Periyodik bakım istiyorum.', 'preferredDate' => $tomorrow, 'requestKey' => $key, 'preferredSlot' => $slot]);
    r2('POST', '/api/requests', $customer, $body('08:00', '88888888-8888-4888-8888-888888888888'))->assertStatus(409);
    $created = r2('POST', '/api/requests', $customer, $body('10:00', '99999999-9999-4999-8999-999999999999'))->assertStatus(201);
    expect($created->json('status'))->toBe('offered'); // otomatik oneri; onay degil
    $req = r2('GET', '/api/requests?v=2', $customer)->assertOk()->json('items.0');
    expect($req['offer']['text'])->toBe(\App\Domain\User\Requests::AUTO_OFFER_TEXT)->and($req)->toHaveKey('preferredStartsAt');
    expect(r2('GET', '/api/requests', $customer)->json('items.0'))->not->toHaveKey('preferredStartsAt');
    r2('PATCH', '/api/requests', $customer, json_encode(['id' => $req['id'], 'version' => 1, 'action' => 'accept']))->assertOk();
    expect(DB::table('appointment_bookings')->count())->toBe(1);
    r2('GET', "/api/businesses/b1/availability?from={$tomorrow}&days=1")->assertJsonPath('days.0.slots', ['09:00', '11:00']);
    r2('POST', '/api/requests', $customer, $body('10:00', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'))->assertStatus(409);

    r2('POST', '/api/businesses/capacity/closures', $owner, json_encode(['businessId' => 'b1', 'from' => $tomorrow, 'to' => $tomorrow, 'reason' => 'holiday']))->assertStatus(201);
    r2('GET', "/api/businesses/b1/availability?from={$tomorrow}&days=1")->assertJsonPath('days.0.slots', []);
    r2('POST', '/api/requests', $customer, $body('09:00', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'))->assertStatus(409);
    r2('PATCH', '/api/businesses/capacity', r2User('u5'), '{"businessId":"b1","expectedVersion":1,"slotMinutes":60,"capacity":2,"approval":"manual","accepting":false}')->assertStatus(403);
    r2('GET', '/api/businesses/b1/availability?from=2000-01-01&days=1')->assertStatus(400);
});

it('portal-05 + web-15: quote lines are summed on the server and the thread is limited to the parties', function () {
    $owner = r2User('o1');
    $customer = r2User('c1');
    r2Business('b1', 'o1');
    $id = r2('POST', '/api/requests', $customer, json_encode(['businessId' => 'b1', 'kind' => 'quotes', 'service' => 'Fren', 'vehicle' => 'Sentetik araç', 'message' => 'Fren balatası değişimi.', 'requestKey' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']))->assertStatus(201)->json('id');
    $offer = fn (array $extra) => json_encode(['businessId' => 'b1', 'value' => ['id' => $id, 'version' => 1, 'action' => 'offer', 'text' => 'Ön inceleme sonrası teklif.'] + $extra]);
    r2('PATCH', '/api/requests', $owner, $offer(['price' => 10, 'lines' => [['kind' => 'labour', 'label' => 'İşçilik', 'amountMinor' => 120000]]]))->assertStatus(409);
    r2('PATCH', '/api/requests', $owner, $offer(['lines' => [['kind' => 'labour', 'label' => 'İşçilik', 'amountMinor' => 120000], ['kind' => 'parts', 'label' => 'Balata', 'amountMinor' => 80050]], 'validDays' => 3, 'excluded' => 'Disk değişimi', 'mayChangeAfterInspection' => true]))->assertOk();
    $v2 = r2('GET', '/api/requests?v=2', $customer)->json('items.0.offer');
    expect($v2['price'])->toBe(2000.5)->and($v2['totalMinor'])->toBe(200050)->and($v2['currency'])->toBe('TRY')->and($v2['lines'])->toHaveCount(2);
    expect(array_keys(r2('GET', '/api/requests', $customer)->json('items.0.offer')))->toBe(['text', 'price', 'expiresAtMs']);

    $msg = fn (string $text, string $op) => json_encode(['id' => $id, 'text' => $text, 'operationId' => $op]);
    r2('POST', '/api/requests/messages', $customer, $msg('Disk de kontrol edilsin mi?', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'))->assertStatus(201)->assertJsonPath('author', 'customer');
    r2('POST', '/api/requests/messages', $owner, $msg('Bana www.ornek.com adresinden yaz', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'))->assertStatus(400);
    r2('POST', '/api/requests/messages', $owner, $msg('Evet, ücretsiz kontrol ederiz.', 'ffffffff-ffff-4fff-8fff-ffffffffffff'))->assertStatus(201)->assertJsonPath('author', 'business');
    r2('GET', '/api/requests/messages?id='.$id, r2User('u9'))->assertStatus(403);
    expect(r2('GET', '/api/requests/messages?id='.$id, $customer)->json('items'))->toHaveCount(2);
    DB::table('requests')->where('id', $id)->update(['status' => 'completed']);
    r2('POST', '/api/requests/messages', $customer, $msg('Teşekkürler', '12121212-1212-4212-8212-121212121212'))->assertStatus(409);
});

it('web-17: lists only the caller\'s own reviews, newest first, with paging', function () {
    $u = r2User('u1');
    r2Business('b1');
    r2Business('b2', null, ['published' => false]);
    foreach (['r1' => ['b1', '2026-11-01 10:00:00.000'], 'r2' => ['b2', '2026-11-05 10:00:00.000']] as $id => [$bid, $at]) {
        DB::table('reviews')->insert(['id' => $id, 'business_id' => $bid, 'user_id' => 'u1', 'rating' => 4, 'resolved' => 'yes', 'wc' => 'unknown', 'text' => 'Sentetik yorum metni yeterince uzun.', 'status' => 'approved', 'updated_at' => $at]);
    }
    DB::table('reviews')->insert(['id' => 'r3', 'business_id' => 'b1', 'user_id' => 'u2', 'rating' => 1, 'resolved' => 'no', 'wc' => 'no', 'text' => 'Başkasının yorumu burada.', 'status' => 'approved']);
    $r = r2('GET', '/api/reviews/mine/all', $u)->assertOk();
    expect(collect($r->json('items'))->pluck('review.id')->all())->toBe(['r2', 'r1'])->and($r->json('items.0.businessAvailable'))->toBeFalse()->and($r->json('next'))->toBeNull();
    r2('GET', '/api/reviews/mine/all?after=bozuk!', $u)->assertStatus(400);
    r2('GET', '/api/reviews/mine/all', r2User('u3', false))->assertStatus(403);
});

it('web-05/07/08: v2 parameters are only accepted with v=2 and the cursor is bound to the filter', function () {
    expect(fn () => SearchFilter::parse(Query::parse('city=İstanbul&sort=rating')))->toThrow(InvalidArgumentException::class);
    $f = SearchFilter::parse(Query::parse('v=2&city='.rawurlencode('İstanbul').'&sort=rating&verifiedOwner=true'));
    expect($f->v2)->toBeTrue()->and($f->sort)->toBe('rating')->and($f->verifiedOwner)->toBeTrue();
    expect(fn () => SearchFilter::parse(Query::parse('v=2&sort=nearest')))->toThrow(InvalidArgumentException::class);
    expect(fn () => SearchFilter::parse(Query::parse('v=3')))->toThrow(InvalidArgumentException::class);
    $cursor = SearchCursor::seal(24, $f->digest());
    expect(SearchCursor::open($cursor, $f->digest()))->toBe(24)
        ->and(SearchCursor::open($cursor, SearchFilter::parse(Query::parse('v=2&sort=rating'))->digest()))->toBeNull()
        ->and(SearchCursor::open(substr($cursor, 0, -2).'xx', $f->digest()))->toBeNull();
    expect(ReviewSummary::score(0, 0))->toBe(3.5)->and(ReviewSummary::score(1, 5))->toBeLessThan(ReviewSummary::score(50, 240)); // 5,0 x1 < 4,8 x50
    expect(ReviewSummary::view(0, 0))->toBe(['count' => 0, 'average' => null]);

    $ok = ['timezone' => 'Europe/Istanbul', 'week' => [['day' => 1, 'intervals' => [['open' => '13:00', 'close' => '18:00'], ['open' => '09:00', 'close' => '12:00']]]], 'exceptions' => []];
    expect(OpeningHours::schema()->parse(json_decode((string) json_encode($ok))))->toBeArray();
    $bad = $ok;
    $bad['week'][0]['intervals'][] = ['open' => '11:00', 'close' => '14:00'];
    expect(fn () => OpeningHours::schema()->parse(json_decode((string) json_encode($bad))))->toThrow(ZodError::class);
    expect(OpeningHours::canonical($ok)['week'][0]['intervals'][0]['open'])->toBe('09:00');
    expect(OpeningHours::view(null))->toBeNull();
});

it('web-07/08: business detail adds hours and review summary only with v=2', function () {
    r2Business('b1', null, ['opening_hours' => r2Hours()]);
    DB::table('public_reviews')->insert(['business_id' => 'b1', 'review_id' => 'r1', 'rating' => 5]);
    expect(r2('GET', '/api/businesses/b1')->assertOk()->json())->not->toHaveKey('reviewSummary')->not->toHaveKey('openingHours');
    $v2 = r2('GET', '/api/businesses/b1?v=2')->assertOk();
    expect($v2->json('reviewSummary'))->toBe(['count' => 1, 'average' => 5])->and($v2->json('openingHours.week'))->toHaveCount(7);
    r2('GET', '/api/businesses/b1?v=9')->assertStatus(400);
});

it('web-03: changes the e-mail only after the new address confirms and signs everyone out', function () {
    $u = r2User('u1');
    r2User('taken');
    r2('POST', '/api/auth/email/change', $u, '{"newEmail":"yeni@example.com","password":"wrong-pass-1"}')->assertStatus(400);
    r2('POST', '/api/auth/email/change', $u, '{"newEmail":"taken@example.com","password":"correct-horse-1"}')->assertStatus(202);
    expect(DB::table('auth_email_changes')->count())->toBe(0);
    r2('POST', '/api/auth/email/change', $u + R2_WEB, '{"newEmail":"Yeni@Example.com","password":"correct-horse-1"}')->assertStatus(202);
    expect(DB::table('auth_accounts')->where('id', 'u1')->value('email'))->toBe('u1@example.com');
    $messages = collect(app('mail.manager')->mailer()->getSymfonyTransport()->messages());
    $link = $messages->first(fn ($m) => str_contains($m->getOriginalMessage()->getTextBody(), 'eposta-dogrula'))->getOriginalMessage()->getTextBody();
    preg_match('/token=([A-Za-z0-9_-]+)/', $link, $m);
    r2('POST', '/api/auth/email/verify', R2_WEB, json_encode(['token' => $m[1]]))->assertOk();
    expect(DB::table('auth_accounts')->where('id', 'u1')->value('email'))->toBe('yeni@example.com');
    r2('GET', '/api/me/preferences', $u)->assertStatus(401);
    r2('POST', '/api/auth/email/verify', R2_WEB, json_encode(['token' => $m[1]]))->assertStatus(400);
});
