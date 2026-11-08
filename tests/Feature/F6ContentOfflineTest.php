<?php

use Illuminate\Support\Facades\DB;

// Veritabani olmadan admin uclari: token -> rol -> limit -> bayrak -> govde.

final class F6FakeStatement extends PDOStatement
{
    public function __construct(private array $rows) {}

    #[ReturnTypeWillChange]
    public function setFetchMode(int $mode, mixed ...$args)
    {
        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

final class F6FakeAuthPdo extends PDO
{
    public function __construct(private bool $verified, private bool $admin) {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $now = gmdate('Y-m-d H:i:s').'.000';
        if (str_contains($query, 'personal_access_tokens')) {
            return new F6FakeStatement([(object) ['id' => 1, 'tokenable_type' => 'account', 'tokenable_id' => 'f6user', 'name' => 'test', 'token' => hash('sha256', 'tok'), 'abilities' => '["*"]', 'session_id' => 's1', 'last_used_at' => null, 'expires_at' => '2099-01-01 00:00:00.000', 'created_at' => $now, 'updated_at' => $now]]);
        }
        if (str_contains($query, 'auth_accounts')) {
            return new F6FakeStatement([(object) ['id' => 'f6user', 'email' => 'u@example.com', 'email_verified_at' => $this->verified ? $now : null, 'tokens_valid_after' => '2020-01-01 00:00:00.000', 'status' => 'active', 'is_admin' => $this->admin ? 1 : 0, 'is_account_erasure_operator' => 0, 'is_brand_finance' => 0, 'second_factor' => null, 'auth_time' => $now, 'revoked_at' => null, 'expires_at' => '2099-01-01 00:00:00.000']]);
        }
        throw new PDOException('f6_no_database_past_auth');
    }

    public function getAttribute(int $attribute): mixed
    {
        return '8.4.13';
    }
}

function f6auth(bool $verified = true, bool $admin = true): array
{
    DB::connection()->setPdo(new F6FakeAuthPdo($verified, $admin));

    return ['HTTP_AUTHORIZATION' => 'Bearer tok', 'HTTP_ACCEPT_LANGUAGE' => '', 'CONTENT_TYPE' => 'application/json'];
}

const F6_REQUIRED = 'Yönetici yetkisi gerekli.';

const F6_VERIFIED = 'Yönetici yetkisi ve doğrulanmış e-posta gerekli.';

$f6plain = [
    ['GET', '/api/admin/business-changes', F6_REQUIRED], ['PATCH', '/api/admin/business-changes', F6_REQUIRED],
    ['GET', '/api/admin/catalogue', F6_REQUIRED], ['PATCH', '/api/admin/catalogue', F6_REQUIRED],
    ['GET', '/api/admin/catalogue/draft?businessId=b', F6_REQUIRED], ['PATCH', '/api/admin/catalogue/draft', F6_REQUIRED],
    ['GET', '/api/admin/catalogue/history?businessId=b', F6_REQUIRED],
    ['POST', '/api/admin/claim-evidence', F6_VERIFIED],
    ['GET', '/api/admin/claim-targets?businessId=b', F6_VERIFIED], ['POST', '/api/admin/claim-targets', F6_VERIFIED],
    ['GET', '/api/admin/claims', F6_REQUIRED], ['PATCH', '/api/admin/claims', F6_VERIFIED],
    ['GET', '/api/admin/replies', F6_REQUIRED], ['PATCH', '/api/admin/replies', F6_REQUIRED],
    ['GET', '/api/admin/review-cases', F6_REQUIRED], ['PATCH', '/api/admin/review-cases', F6_REQUIRED],
    ['GET', '/api/admin/review-photos', F6_REQUIRED], ['PATCH', '/api/admin/review-photos', F6_REQUIRED],
    ['GET', '/api/admin/reviews', F6_REQUIRED], ['PATCH', '/api/admin/reviews', F6_REQUIRED],
    ['POST', '/api/admin/reviews/analyze', F6_REQUIRED],
];

$f6photos = [['GET', '/api/admin/business-photos'], ['PATCH', '/api/admin/business-photos']];

it('anonymous is 401', function (string $m, string $u) {
    $r = $this->call($m, $u, [], [], [], ['HTTP_ACCEPT_LANGUAGE' => '', 'CONTENT_TYPE' => 'application/json'], '{}');
    $r->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
    expect($r->headers->get('Cache-Control'))->toBe('private, no-store');
})->with(array_map(static fn (array $x): array => [$x[0], $x[1]], [...$f6plain, ...$f6photos]));

it('a non-admin is 403 with the route message', function (string $m, string $u, string $message) {
    $this->call($m, $u, [], [], [], f6auth(true, false), '{}')->assertStatus(403)->assertExactJson(['error' => $message]);
})->with($f6plain);

it('an admin with an unverified e-mail is 403', function (string $m, string $u, string $message) {
    $this->call($m, $u, [], [], [], f6auth(false, true), '{}')->assertStatus(403)->assertExactJson(['error' => $message]);
})->with($f6plain);

it('business-photos: non-admin and unverified share the route refusal', function (string $m, string $u) {
    $this->call($m, $u, [], [], [], f6auth(true, false), '{}')->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
    $this->call($m, $u, [], [], [], f6auth(false, true), '{}')->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
})->with($f6photos);

it('only business-photos checks the origin', function () {
    $o = ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_ACCEPT_LANGUAGE' => ''];
    foreach (['GET', 'PATCH', 'OPTIONS'] as $m) {
        $r = $this->call($m, '/api/admin/business-photos', [], [], [], $o, '{}');
        $r->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
        expect($r->headers->has('Access-Control-Allow-Origin'))->toBeFalse()->and($r->headers->get('Vary'))->toBe('Origin');
    }
    $this->call('GET', '/api/admin/reviews', [], [], [], $o)->assertStatus(401);
});

it('business-photos preflight lists admin methods', function () {
    $r = $this->call('OPTIONS', '/api/admin/business-photos', [], [], [], ['HTTP_ORIGIN' => 'https://m.otosanayide.test']);
    $r->assertStatus(204);
    expect($r->headers->get('Access-Control-Allow-Methods'))->toBe('GET, PATCH, OPTIONS')->and($r->headers->get('Access-Control-Allow-Headers'))->toBe('Authorization, Content-Type, Accept, Accept-Language')->and($r->headers->get('Access-Control-Max-Age'))->toBe('600')->and($r->headers->get('Access-Control-Allow-Origin'))->toBe('https://m.otosanayide.test');
});

it('business-photos validates query and content type before auth', function () {
    foreach (['/api/admin/business-photos?x=1', '/api/admin/business-photos?status=rejected', '/api/admin/business-photos?after=nope', '/api/admin/business-photos?status=pending&status=approved'] as $u) {
        $this->get($u)->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    }
    $this->call('PATCH', '/api/admin/business-photos?x=1', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}')->assertStatus(400);
    $this->call('PATCH', '/api/admin/business-photos', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}')->assertStatus(415)->assertExactJson(['error' => 'Geçersiz istek.']);
});

it('rejects oversize bodies after the role check', function (string $m, string $u) {
    $this->call($m, $u, [], [], [], f6auth(), '{"x":"'.str_repeat('a', 20000).'"}')->assertStatus(413)->assertExactJson(['error' => 'İstek çok büyük.']);
})->with([['PATCH', '/api/admin/business-changes'], ['PATCH', '/api/admin/catalogue'], ['PATCH', '/api/admin/catalogue/draft'], ['POST', '/api/admin/claim-targets'], ['PATCH', '/api/admin/claims'], ['PATCH', '/api/admin/replies'], ['PATCH', '/api/admin/review-cases'], ['PATCH', '/api/admin/review-photos'], ['PATCH', '/api/admin/reviews'], ['PATCH', '/api/admin/business-photos']]);

it('validates bodies without touching the database', function (string $m, string $u, string $body, string $error) {
    $this->call($m, $u, [], [], [], f6auth(), $body)->assertStatus(400)->assertExactJson(['error' => $error]);
})->with([
    ['PATCH', '/api/admin/reviews', '{bad', 'Geçersiz istek.'],
    ['PATCH', '/api/admin/reviews', '{"id":"r","version":1,"decision":"approve","reason":"İnceleme tamamlandı.","userId":"x"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/replies', '{"id":"r","version":0,"decision":"hide","reason":"İnceleme tamamlandı."}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/review-cases', '{"id":"c","decision":"remove","reason":"Yeterince uzun gerekçe","reviewed":false}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/review-photos', '{"id":"1","version":1,"decision":"approve","reason":"Yeterince uzun gerekçe","attested":true}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/business-photos', '{"id":"1","version":1,"decision":"approve","reason":"Yeterince uzun gerekçe","attested":true}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/claims', '{"claimId":"c","decision":"approve","reason":"Yeterince uzun gerekçe","evidenceChecked":false,"verificationReference":"v"}', 'Bilgileri kontrol edin.'],
    ['POST', '/api/admin/claim-targets', '{"mode":"existing","claimId":"c","reason":"Yeterince uzun gerekçe","identityChecked":false,"businessId":"b"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/business-changes', '{"businessId":"b","revisionId":"x","decision":"approve","reason":"Yeterince uzun gerekçe"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/catalogue', '{"businessId":"b","version":0,"profileVersion":0,"action":"publish","reason":"Yeterince uzun gerekçe"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/admin/catalogue/draft', '{"businessId":"b","profileVersion":0,"publicationVersion":0,"reason":"Yeterince uzun gerekçe","profile":{}}', 'Bilgileri kontrol edin.'],
]);

it('validates query fields after the role check', function (string $u, string $error) {
    $this->call('GET', $u, [], [], [], f6auth())->assertStatus(400)->assertExactJson(['error' => $error]);
})->with([
    ['/api/admin/reviews?status=all', 'Geçersiz inceleme durumu.'], ['/api/admin/replies?status=all', 'Geçersiz inceleme durumu.'], ['/api/admin/review-photos?status=all', 'Geçersiz inceleme durumu.'],
    ['/api/admin/reviews?after=../x', 'Bilgileri kontrol edin.'], ['/api/admin/review-cases?after=../x', 'Bilgileri kontrol edin.'], ['/api/admin/catalogue?after=../x', 'Bilgileri kontrol edin.'],
    ['/api/admin/catalogue/draft', 'Bilgileri kontrol edin.'], ['/api/admin/claim-targets', 'Bilgileri kontrol edin.'],
    ['/api/admin/catalogue/history?businessId=b&businessId=c', 'Geçersiz istek.'], ['/api/admin/catalogue/history', 'Bilgileri kontrol edin.'], ['/api/admin/catalogue/history?businessId=b&limit=9', 'Bilgileri kontrol edin.'],
]);

it('analyze is closed by flag or missing key before reading the body', function () {
    $this->call('POST', '/api/admin/reviews/analyze', [], [], [], f6auth(), '{bad')->assertStatus(503)->assertExactJson(['error' => 'AI kontrolü henüz yapılandırılmadı. Manuel inceleme gerekli.']);
    config(['otosanayide.flags.ai_moderation' => true]);
    $this->call('POST', '/api/admin/reviews/analyze', [], [], [], f6auth(), '{bad')->assertStatus(503);
    config(['otosanayide.openai_api_key' => 'synthetic-key']);
    $this->call('POST', '/api/admin/reviews/analyze', [], [], [], f6auth(), '{"id":"../r","version":1}')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
    $this->call('POST', '/api/admin/reviews/analyze', [], [], [], f6auth(), '{"id":"r","version":1,"kind":"other"}')->assertStatus(400);
});

it('claim evidence checks the store before the body', function () {
    $this->call('POST', '/api/admin/claim-evidence', [], [], [], f6auth(), '{bad')->assertStatus(503)->assertExactJson(['error' => 'Güvenli belge yükleme henüz açılmadı.']);
    config(['otosanayide.flags.claim_evidence' => true]);
    $this->call('POST', '/api/admin/claim-evidence', [], [], [], f6auth(), '{bad')->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    $this->call('POST', '/api/admin/claim-evidence', [], [], [], f6auth(), '{"claimId":"c","evidenceIds":[],"evidenceRevision":1,"attested":true,"reason":"Yeterince uzun gerekçe"}')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
});

it('rate limit is 429 after 60 operations', function () {
    $auth = f6auth();
    foreach (range(1, 60) as $i) {
        $this->call('GET', '/api/admin/claims', [], [], [], $auth)->assertStatus(500);
    }
    $this->call('GET', '/api/admin/claims', [], [], [], $auth)->assertStatus(429)->assertExactJson(['error' => 'Çok fazla işlem. Bir dakika sonra yeniden deneyin.']);
});
