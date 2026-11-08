<?php

use Illuminate\Support\Facades\DB;

// Veritabani olmadan: origin -> sorgu -> icerik tipi -> actor -> e-posta -> limit -> govde sirasi.
// F5FakeAuthPdo yalniz kimlik sorgularini yanitlar; handler veritabanina gecerse istisna atar ve test 500 gorur.

final class F5FakeStatement extends PDOStatement
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

final class F5FakeAuthPdo extends PDO
{
    public function __construct(private bool $verified, private bool $admin) {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $now = gmdate('Y-m-d H:i:s').'.000';
        if (str_contains($query, 'personal_access_tokens')) {
            return new F5FakeStatement([(object) ['id' => 1, 'tokenable_type' => 'account', 'tokenable_id' => 'f5user', 'name' => 'test', 'token' => hash('sha256', 'tok'), 'abilities' => '["*"]', 'session_id' => 's1', 'last_used_at' => null, 'expires_at' => '2099-01-01 00:00:00.000', 'created_at' => $now, 'updated_at' => $now]]);
        }
        if (str_contains($query, 'auth_accounts')) {
            return new F5FakeStatement([(object) ['id' => 'f5user', 'email' => 'u@example.com', 'email_verified_at' => $this->verified ? $now : null, 'tokens_valid_after' => '2020-01-01 00:00:00.000', 'status' => 'active', 'is_admin' => $this->admin ? 1 : 0, 'is_account_erasure_operator' => 0, 'is_brand_finance' => 0, 'second_factor' => null, 'auth_time' => $now, 'revoked_at' => null, 'expires_at' => '2099-01-01 00:00:00.000']]);
        }
        throw new PDOException('f5_no_database_past_auth');
    }

    public function getAttribute(int $attribute): mixed
    {
        return '8.4.13';
    }
}

function f5auth(bool $verified = true, bool $admin = false): array
{
    DB::connection()->setPdo(new F5FakeAuthPdo($verified, $admin));

    return ['HTTP_AUTHORIZATION' => 'Bearer tok', 'HTTP_ACCEPT_LANGUAGE' => ''];
}

function f5json($test, string $method, string $uri, array $server, string $body = '{}')
{
    return $test->call($method, $uri, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], $body);
}

const F5_UUID = '11111111-1111-4111-8111-111111111111';

const F5_ROUTES = [
    '/api/reviews' => 'GET, POST, OPTIONS', '/api/reviews/mine' => 'GET, PATCH, OPTIONS', '/api/review-cases' => 'GET, POST, OPTIONS',
    '/api/review-photos' => 'GET, POST, PATCH, OPTIONS', '/api/review-photos/media' => 'GET, OPTIONS',
    '/api/businesses/manage' => 'GET, PATCH, OPTIONS', '/api/businesses/replies' => 'GET, POST, PATCH, OPTIONS', '/api/businesses/activation' => 'GET, PATCH, OPTIONS',
    '/api/businesses/console' => 'GET, OPTIONS', '/api/businesses/places' => 'POST, OPTIONS',
];

// Anonim 401: sorgu ve icerik tipi gecerli, kimlik basligi yok.
$anon = [
    ['POST', '/api/reviews', 'application/json'], ['GET', '/api/reviews/mine?businessId=b1', null], ['PATCH', '/api/reviews/mine', 'application/json'],
    ['GET', '/api/review-cases', null], ['POST', '/api/review-cases', 'application/json'],
    ['GET', '/api/review-photos?reviewId=r1', null], ['POST', '/api/review-photos?reviewId=r1&rights=yes', 'image/webp'], ['PATCH', '/api/review-photos', 'application/json'],
    ['GET', '/api/claims', null], ['POST', '/api/claims', 'application/json'], ['PATCH', '/api/claims', 'application/json'],
    ['GET', '/api/claims/evidence?claimId=c1', null], ['POST', '/api/claims/evidence?claimId=c1&kind=workplace', 'image/webp'], ['PATCH', '/api/claims/evidence', 'application/json'],
    ['GET', '/api/businesses/manage', null], ['PATCH', '/api/businesses/manage', 'application/json'],
    ['GET', '/api/businesses/photos?businessId=b1', null], ['POST', '/api/businesses/photos?businessId=b1&rights=yes&requestId='.F5_UUID, 'image/webp'], ['PATCH', '/api/businesses/photos', 'application/json'],
    ['GET', '/api/businesses/replies?businessId=b1', null], ['POST', '/api/businesses/replies', 'application/json'], ['PATCH', '/api/businesses/replies', 'application/json'],
    ['GET', '/api/businesses/activation?businessId=b1', null], ['PATCH', '/api/businesses/activation', 'application/json'],
    ['GET', '/api/businesses/console', null], ['POST', '/api/businesses/places', 'application/json'],
];

it('anonymous is 401 on every protected method', function (string $m, string $u, ?string $type) {
    $r = $this->call($m, $u, [], [], [], ['HTTP_ACCEPT_LANGUAGE' => ''] + ($type ? ['CONTENT_TYPE' => $type] : []), $type ? '{}' : null);
    $r->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
    expect($r->headers->get('Cache-Control'))->toBe('private, no-store');
})->with($anon);

it('a syntactically invalid bearer scheme is 401', function () {
    $this->call('GET', '/api/review-cases', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic abc', 'HTTP_ACCEPT_LANGUAGE' => ''])->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
});

// Yanlis origin
$cors = [['POST', '/api/reviews'], ['GET', '/api/reviews/mine?businessId=b'], ['PATCH', '/api/reviews/mine'], ['GET', '/api/review-cases'], ['POST', '/api/review-cases'], ['GET', '/api/review-photos?reviewId=r'], ['POST', '/api/review-photos?reviewId=r&rights=yes'], ['PATCH', '/api/review-photos'], ['GET', '/api/businesses/manage'], ['PATCH', '/api/businesses/manage'], ['GET', '/api/businesses/replies?businessId=b'], ['POST', '/api/businesses/replies'], ['PATCH', '/api/businesses/replies'], ['POST', '/api/businesses/places'], ['GET', '/api/businesses/photos?businessId=b'], ['POST', '/api/businesses/photos'], ['PATCH', '/api/businesses/photos'], ['GET', '/api/businesses/photos/media?businessId=b&id='.F5_UUID], ['GET', '/api/businesses/photos/public?businessId=b'], ['OPTIONS', '/api/businesses/photos'], ['OPTIONS', '/api/businesses/photos/media'], ['OPTIONS', '/api/businesses/photos/public']];

it('a foreign origin is refused before anything else', function (string $m, string $u) {
    $r = $this->call($m, $u, [], [], [], ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}');
    $r->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
    expect($r->headers->has('Access-Control-Allow-Origin'))->toBeFalse()->and($r->headers->get('Vary'))->toBe('Origin')->and($r->headers->get('Cache-Control'))->toBe('private, no-store');
})->with($cors);

it('activation and console use their own refusal texts, public reads say Forbidden', function () {
    $o = ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_ACCEPT_LANGUAGE' => ''];
    $this->call('GET', '/api/businesses/activation?businessId=b', [], [], [], $o)->assertStatus(403)->assertExactJson(['error' => 'Yetkiniz yok.']);
    $this->call('PATCH', '/api/businesses/activation', [], [], [], $o)->assertStatus(403)->assertExactJson(['error' => 'Yetkiniz yok.']);
    $this->call('GET', '/api/businesses/console', [], [], [], $o)->assertStatus(403)->assertExactJson(['error' => "Bu i\u{C5}\u{178}lem i\u{C3}\u{A7}in yetkiniz yok."]);
    $this->call('GET', '/api/reviews?businessId=b', [], [], [], $o)->assertStatus(403)->assertExactJson(['error' => 'Forbidden']);
    $this->call('GET', '/api/review-photos/media?id='.F5_UUID, [], [], [], $o)->assertStatus(403)->assertExactJson(['error' => 'Forbidden']);
});

it('answers preflights with the source methods and headers', function (string $uri, string $methods) {
    $ok = $this->call('OPTIONS', $uri, [], [], [], ['HTTP_ORIGIN' => 'https://m.otosanayide.test']);
    $ok->assertStatus(204);
    $headers = in_array($uri, ['/api/review-photos/media', '/api/businesses/console'], true) ? 'Authorization, Accept, Accept-Language' : 'Authorization, Content-Type, Accept, Accept-Language';
    expect($ok->headers->get('Access-Control-Allow-Methods'))->toBe($methods)
        ->and($ok->headers->get('Access-Control-Allow-Headers'))->toBe($headers)
        ->and($ok->headers->get('Access-Control-Max-Age'))->toBe('600')
        ->and($ok->headers->get('Access-Control-Allow-Origin'))->toBe('https://m.otosanayide.test')
        ->and($ok->headers->get('Cache-Control'))->toBe('private, no-store')
        ->and($ok->headers->get('Vary'))->toBe('Origin');
    $bad = $this->call('OPTIONS', $uri, [], [], [], ['HTTP_ORIGIN' => 'https://evil.test']);
    $bad->assertStatus(403);
    expect($bad->getContent())->toBe('')->and($bad->headers->has('Access-Control-Allow-Methods'))->toBeFalse();
})->with(array_map(null, array_keys(F5_ROUTES), array_values(F5_ROUTES)));

it('business photo preflights match the handler map', function () {
    $o = ['HTTP_ORIGIN' => 'https://m.otosanayide.test'];
    expect($this->call('OPTIONS', '/api/businesses/photos', [], [], [], $o)->assertStatus(204)->headers->get('Access-Control-Allow-Methods'))->toBe('GET, POST, PATCH, OPTIONS')
        ->and($this->call('OPTIONS', '/api/businesses/photos/media', [], [], [], $o)->assertStatus(204)->headers->get('Access-Control-Allow-Methods'))->toBe('GET, OPTIONS')
        ->and($this->call('OPTIONS', '/api/businesses/photos/public', [], [], [], $o)->assertStatus(204)->headers->get('Access-Control-Allow-Methods'))->toBe('GET, OPTIONS');
});

it('claims routes behave like Next without an OPTIONS handler and have no origin gate', function () {
    foreach (['/api/claims', '/api/claims/evidence'] as $u) {
        $r = $this->call('OPTIONS', $u, [], [], [], ['HTTP_ORIGIN' => 'https://evil.test']);
        $r->assertStatus(204);
        expect($r->headers->get('Allow'))->toBe('GET, HEAD, OPTIONS, PATCH, POST')->and($r->headers->has('Access-Control-Allow-Origin'))->toBeFalse()->and($r->headers->has('Cache-Control'))->toBeFalse();
    }
    $this->call('GET', '/api/claims', [], [], [], ['HTTP_ORIGIN' => 'https://evil.test', 'HTTP_ACCEPT_LANGUAGE' => ''])->assertStatus(401);
});

it('rejects bad queries before auth', function (string $m, string $u, ?string $type = null) {
    $this->call($m, $u, [], [], [], ['HTTP_ACCEPT_LANGUAGE' => ''] + ($type ? ['CONTENT_TYPE' => $type] : []), $type ? '{}' : null)->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
})->with([
    ['GET', '/api/reviews'], ['GET', '/api/reviews?businessId=b&uid=other'], ['GET', '/api/reviews?businessId=b&businessId=c'], ['GET', '/api/reviews?businessId=../other'], ['GET', '/api/reviews?businessId=b&after=../r'],
    ['GET', '/api/reviews/mine'], ['GET', '/api/reviews/mine?businessId=b&businessId=c'], ['GET', '/api/reviews/mine?businessId=b&uid=x'], ['GET', '/api/reviews/mine?businessId=../x'],
    ['PATCH', '/api/reviews/mine?uid=x', 'application/json'], ['POST', '/api/reviews?x=1', 'application/json'],
    ['GET', '/api/review-cases?after=../x'], ['GET', '/api/review-cases?after=c1&after=c2'], ['GET', '/api/review-cases?uid=other'], ['POST', '/api/review-cases?after=c1', 'application/json'],
    ['GET', '/api/review-photos?reviewId=r&reviewId=x'], ['GET', '/api/review-photos?reviewId=r&uid=x'], ['GET', '/api/review-photos?reviewId=r&version=0'], ['GET', '/api/review-photos?reviewId=r&version=1.2'], ['GET', '/api/review-photos?reviewId=r&version=9999999999999999'], ['PATCH', '/api/review-photos?id=x', 'application/json'],
    ['GET', '/api/review-photos/media?id='.F5_UUID.'&id='.F5_UUID], ['GET', '/api/review-photos/media?id='.F5_UUID.'&uid=other'], ['GET', '/api/review-photos/media?id='.F5_UUID.'&size=huge'], ['GET', '/api/review-photos/media?id=https://private.invalid'], ['GET', '/api/review-photos/media'],
    ['GET', '/api/businesses/manage?uid=other'], ['GET', '/api/businesses/manage?businessId=../x'], ['GET', '/api/businesses/manage?businessId=b1&businessId=b2'], ['GET', '/api/businesses/manage?businessId='], ['PATCH', '/api/businesses/manage?businessId=b1', 'application/json'],
    ['GET', '/api/businesses/photos'], ['GET', '/api/businesses/photos?businessId=../x'], ['GET', '/api/businesses/photos?businessId=b1&uid=bob'], ['GET', '/api/businesses/photos?businessId=b1&businessId=b2'], ['PATCH', '/api/businesses/photos?businessId=b1', 'application/json'],
    ['GET', '/api/businesses/photos/media?businessId=b1&id='.F5_UUID.'&size=full'], ['GET', '/api/businesses/photos/public'],
    ['GET', '/api/businesses/replies?businessId=b1&businessId=b2'], ['GET', '/api/businesses/replies?businessId=b1&uid=other'], ['GET', '/api/businesses/replies?businessId=../x'], ['POST', '/api/businesses/replies?businessId=b1', 'application/json'],
    ['GET', '/api/businesses/activation'], ['GET', '/api/businesses/activation?businessId='], ['GET', '/api/businesses/activation?businessId=../x'], ['GET', '/api/businesses/activation?businessId=b1&businessId=b2'], ['GET', '/api/businesses/activation?businessId=b1&uid=bob'], ['GET', '/api/businesses/activation?queue=false&businessId=b1'], ['GET', '/api/businesses/activation?queue=true&businessId=b1'], ['GET', '/api/businesses/activation?queue=true&status=all'], ['GET', '/api/businesses/activation?queue=true&after=../x'], ['GET', '/api/businesses/activation?queue=true&queue=true'], ['PATCH', '/api/businesses/activation?businessId=b1', 'application/json'],
]);

it('console query failures keep the source text', function () {
    $this->get('/api/businesses/console?uid=x')->assertStatus(400)->assertExactJson(['error' => "Ge\u{C3}\u{A7}ersiz istek."]);
    $this->get('/api/businesses/console?scope=all')->assertStatus(400);
    $this->get('/api/businesses/console?scope=profile&scope=profile')->assertStatus(400);
});

it('rejects deceptive content types with 415 before auth', function (string $m, string $u, string $type) {
    $this->call($m, $u, [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT_LANGUAGE' => ''], '{}')->assertStatus(415)->assertExactJson(['error' => 'Geçersiz istek.']);
})->with([
    ['POST', '/api/reviews', 'application/jsonx'], ['PATCH', '/api/reviews/mine', 'text/plain'], ['POST', '/api/review-cases', 'text/plain'], ['PATCH', '/api/review-photos', 'text/plain'],
    ['POST', '/api/review-photos?reviewId=r&rights=yes', 'image/svg+xml'], ['POST', '/api/review-photos?reviewId=r&rights=no', 'image/webp'],
    ['PATCH', '/api/businesses/manage', 'text/plain'], ['POST', '/api/businesses/replies', 'text/plain'], ['PATCH', '/api/businesses/replies', 'text/plain'], ['PATCH', '/api/businesses/activation', 'text/plain'],
    ['POST', '/api/businesses/photos?businessId=b1&rights=yes&requestId='.F5_UUID, 'text/plain'], ['POST', '/api/businesses/photos?businessId=b1&rights=yes&requestId='.F5_UUID, 'image/png'], ['PATCH', '/api/businesses/photos', 'text/plain'],
]);

it('accepts charset parameters and the legacy empty cursor before auth', function (string $m, string $u, ?string $type) {
    $this->call($m, $u, [], [], [], ['HTTP_ACCEPT_LANGUAGE' => ''] + ($type ? ['CONTENT_TYPE' => $type] : []), $type ? '{}' : null)->assertStatus(401);
})->with([
    ['POST', '/api/review-cases', 'application/json; charset=utf-8'], ['GET', '/api/review-cases?after=', null],
    ['GET', '/api/businesses/replies?businessId=b1&after=', null], ['GET', '/api/businesses/console?after=', null], ['GET', '/api/businesses/activation?queue=true&status=pending&after=', null],
    ['GET', '/api/review-photos?reviewId=r&version=3', null],
]);

// Sahte kimlikle: DB'ye gecmeden biten dallar.

it('unverified e-mail is 403 with the route message', function (string $m, string $u, ?string $type, string $message) {
    $this->call($m, $u, [], [], [], f5auth(false) + ($type ? ['CONTENT_TYPE' => $type] : []), $type ? '{}' : null)->assertStatus(403)->assertExactJson(['error' => $message]);
})->with([
    ['POST', '/api/reviews', 'application/json', 'E-posta doğrulaması gerekli.'], ['GET', '/api/reviews/mine?businessId=b1', null, 'E-posta doğrulaması gerekli.'], ['PATCH', '/api/reviews/mine', 'application/json', 'E-posta doğrulaması gerekli.'],
    ['POST', '/api/review-cases', 'application/json', 'E-posta doğrulaması gerekli.'],
    ['GET', '/api/review-photos?reviewId=r1', null, 'E-posta doğrulaması gerekli.'], ['POST', '/api/review-photos?reviewId=r1&rights=yes', 'image/webp', 'E-posta doğrulaması gerekli.'], ['PATCH', '/api/review-photos', 'application/json', 'E-posta doğrulaması gerekli.'],
    ['POST', '/api/claims', 'application/json', 'E-posta doğrulaması gerekli.'], ['PATCH', '/api/claims', 'application/json', 'E-posta doğrulaması gerekli.'],
    ['POST', '/api/claims/evidence?claimId=c1&kind=workplace', 'image/webp', 'E-posta doğrulaması gerekli.'], ['PATCH', '/api/claims/evidence', 'application/json', 'E-posta doğrulaması gerekli.'],
    ['PATCH', '/api/businesses/manage', 'application/json', 'E-posta doğrulaması gerekli.'], ['GET', '/api/businesses/manage?businessId=b1', null, 'E-posta doğrulaması gerekli.'],
    ['GET', '/api/businesses/replies?businessId=b1', null, 'E-posta doğrulaması gerekli.'], ['POST', '/api/businesses/replies', 'application/json', 'E-posta doğrulaması gerekli.'], ['PATCH', '/api/businesses/replies', 'application/json', 'E-posta doğrulaması gerekli.'],
    ['GET', '/api/businesses/activation?businessId=b1', null, 'E-posta doğrulaması gerekli.'], ['PATCH', '/api/businesses/activation', 'application/json', 'E-posta doğrulaması gerekli.'],
    ['GET', '/api/businesses/console', null, "E-posta do\u{C4}\u{178}rulamas\u{C4}\u{B1} gerekli."],
    ['POST', '/api/businesses/places', 'application/json', 'Doğrulanmış hesap gerekli.'],
    ['GET', '/api/businesses/photos?businessId=b1', null, 'Bu işlem için yetkiniz yok.'], ['POST', '/api/businesses/photos?businessId=b1&rights=yes&requestId='.F5_UUID, 'image/webp', 'Bu işlem için yetkiniz yok.'], ['PATCH', '/api/businesses/photos', 'application/json', 'Bu işlem için yetkiniz yok.'],
]);

it('reading own claims, cases and managed lists needs no verified e-mail', function (string $u) {
    $this->call('GET', $u, [], [], [], f5auth(false))->assertStatus(500);
})->with(['/api/claims', '/api/review-cases', '/api/businesses/manage', '/api/claims/evidence?claimId=c1']);

it('places checks query and content type after auth and before verification', function () {
    $this->call('POST', '/api/businesses/places?x=1', [], [], [], f5auth(false) + ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    $this->call('POST', '/api/businesses/places', [], [], [], f5auth(false) + ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(400);
    $this->call('POST', '/api/businesses/places?x=1', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], '{}')->assertStatus(401);
});

it('places is closed by flag or missing key after the limit', function () {
    $this->call('POST', '/api/businesses/places', [], [], [], f5auth() + ['CONTENT_TYPE' => 'application/json'], '{"bad":true}')->assertStatus(503)->assertExactJson(['error' => 'Adres araması henüz yapılandırılmadı.']);
    config(['otosanayide.flags.google_places' => true]);
    $this->call('POST', '/api/businesses/places', [], [], [], f5auth() + ['CONTENT_TYPE' => 'application/json'], '{"bad":true}')->assertStatus(503);
    config(['otosanayide.google_places_api_key' => 'synthetic-key']);
    $this->call('POST', '/api/businesses/places', [], [], [], f5auth() + ['CONTENT_TYPE' => 'application/json'], '{"bad":true}')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
});

it('closed media flags return the source closed responses without storage', function () {
    $empty = ['businessId' => 'b1', 'ids' => [], 'coverId' => null];
    $this->get('/api/businesses/photos/public?businessId=b1')->assertOk()->assertExactJson($empty);
    $this->get('/api/businesses/photos/media?businessId=b1&id='.F5_UUID)->assertStatus(404)->assertExactJson(['error' => 'Fotoğraf bulunamadı.']);
});

it('oversize body is 413 after the limit', function (string $m, string $u) {
    f5json($this, $m, $u, f5auth(), '{"x":"'.str_repeat('a', 20000).'"}')->assertStatus(413)->assertExactJson(['error' => 'İstek çok büyük.']);
})->with([['POST', '/api/reviews'], ['PATCH', '/api/reviews/mine'], ['POST', '/api/review-cases'], ['PATCH', '/api/review-photos'], ['POST', '/api/claims'], ['PATCH', '/api/claims'], ['PATCH', '/api/claims/evidence'], ['PATCH', '/api/businesses/manage'], ['PATCH', '/api/businesses/photos'], ['POST', '/api/businesses/replies'], ['PATCH', '/api/businesses/replies'], ['PATCH', '/api/businesses/activation']]);

it('declared content length over the limit is 413', function () {
    $this->call('POST', '/api/reviews', [], [], [], f5auth() + ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '20001'], '{}')->assertStatus(413);
});

it('validates bodies after auth, without touching the database', function (string $m, string $u, string $body, string $error) {
    f5json($this, $m, $u, f5auth(), $body)->assertStatus(400)->assertExactJson(['error' => $error]);
})->with([
    ['POST', '/api/reviews', '{bad', 'Geçersiz istek.'],
    ['POST', '/api/reviews', '[]', 'Bilgileri kontrol edin.'],
    ['POST', '/api/reviews', '{"businessId":"b","rating":3,"resolved":"yes","wc":"no","text":"yeterince uzun metin","userId":"spoof"}', 'Bilgileri kontrol edin.'],
    ['POST', '/api/reviews', '{"businessId":"../b","rating":3,"resolved":"yes","wc":"no","text":"yeterince uzun metin"}', 'Bilgileri kontrol edin.'],
    ['POST', '/api/reviews', '{"businessId":"b","rating":3,"resolved":"yes","wc":"no","wcCleanliness":3,"text":"yeterince uzun metin"}', 'WC temizliği sadece WC kullanımı belirtildiğinde puanlanabilir.'],
    ['PATCH', '/api/reviews/mine', '{"action":"edit","businessId":"b","expectedVersion":1,"review":{"businessId":"c","rating":3,"resolved":"yes","wc":"no","text":"yeterince uzun metin"}}', 'Yorum işletmesi değiştirilemez.'],
    ['POST', '/api/review-cases', '{"reviewId":"r","kind":"other","text":"yeterince uzun metin"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/review-photos', '{"id":"x","version":1}', 'Bilgileri kontrol edin.'],
    ['POST', '/api/claims', '{"name":"A"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/claims/evidence', '{"claimId":"c","id":"x","action":"withdraw"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/businesses/manage', '{"businessId":"b","expectedVersion":0,"expectedRevisionId":null,"profile":{}}', 'Bilgileri kontrol edin.'],
    ['POST', '/api/businesses/replies', '{"businessId":"b","reviewId":"r","expectedVersion":0,"text":"kısa"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/businesses/activation', '{"businessId":"b","version":0,"action":"request","features":{"quotes":false,"appointments":false},"monitorInbox":true,"capacityReady":true}', 'Invalid input'],
    ['PATCH', '/api/businesses/activation', '{"businessId":"b","version":0,"action":"approve","reason":"kisa"}', 'Bilgileri kontrol edin.'],
    ['PATCH', '/api/businesses/photos', '{"businessId":"b","id":"x","version":1,"action":"cover"}', 'Bilgileri kontrol edin.'],
]);

it('evidence and review-photo query fields are validated after auth', function () {
    $this->call('GET', '/api/claims/evidence', [], [], [], f5auth())->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
    $this->call('POST', '/api/claims/evidence?claimId=c1&kind=other', [], [], [], f5auth() + ['CONTENT_TYPE' => 'image/webp'], 'x')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
});

it('activation queue needs an administrator', function () {
    $this->call('GET', '/api/businesses/activation?queue=true', [], [], [], f5auth())->assertStatus(403)->assertExactJson(['error' => 'Yetkiniz yok.']);
});

it('the admin activation queue passes the role check', function () {
    $this->call('GET', '/api/businesses/activation?queue=true', [], [], [], f5auth(true, true))->assertStatus(500);
});

it('rate limit is 429 after 60 operations', function () {
    $auth = f5auth();
    foreach (range(1, 60) as $i) {
        $this->call('GET', '/api/review-cases', [], [], [], $auth)->assertStatus(500);
    }
    $r = $this->call('GET', '/api/review-cases', [], [], [], $auth);
    $r->assertStatus(429)->assertExactJson(['error' => 'Çok fazla işlem. Bir dakika sonra yeniden deneyin.']);
    expect($r->headers->get('Cache-Control'))->toBe('private, no-store');
});

it('echoes the allowed origin on errors', function () {
    $r = $this->call('GET', '/api/reviews/mine?businessId=b1', [], [], [], ['HTTP_ORIGIN' => 'https://m.otosanayide.test', 'HTTP_ACCEPT_LANGUAGE' => '']);
    $r->assertStatus(401);
    expect($r->headers->get('Access-Control-Allow-Origin'))->toBe('https://m.otosanayide.test');
});
