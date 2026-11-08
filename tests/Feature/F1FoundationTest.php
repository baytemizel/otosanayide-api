<?php

use App\Exceptions\HttpError;
use App\Exceptions\WriteConflict;
use App\Support\Body;
use App\Support\RateLimit;
use App\Support\Z;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

function rawPost($test, string $uri, string $body, array $server = [])
{
    return $test->call('POST', $uri, [], [], [], $server + ['HTTP_ACCEPT_LANGUAGE' => ''], $body);
}

function probe(string $path, Closure $handler, array $middleware = [], string $method = 'post'): void
{
    Route::prefix('api')->middleware($middleware)->{$method}($path, $handler);
}

it('health is liveness only with exact Cache-Control', function () {
    $r = $this->get('/api/health');
    $r->assertOk()->assertExactJson(['status' => 'ok']);
    expect($r->headers->get('Cache-Control'))->toBe('no-store');
});

it('renders unknown routes and wrong methods as error bodies', function () {
    $this->getJson('/api/yok')->assertStatus(404)->assertJsonStructure(['error'])->assertJsonMissingPath('message');
    $this->postJson('/api/health')->assertStatus(405)->assertJsonStructure(['error']);
});

it('maps HttpError with translation and private no-store', function () {
    probe('x', fn () => throw new HttpError(403, 'Bu işlem için yetkiniz yok.'));
    $r = $this->post('/api/x');
    $r->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
    expect($r->headers->get('Cache-Control'))->toBe('private, no-store');
});

it('hides internal errors, stack traces and exception text', function () {
    probe('boom', fn () => throw new RuntimeException('secret detail'));
    $r = $this->post('/api/boom');
    $r->assertStatus(500)->assertExactJson(['error' => 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.']);
    expect($r->getContent())->not->toContain('secret detail');
});

it('maps write conflicts to 409', function () {
    probe('c', fn () => throw new WriteConflict);
    $this->post('/api/c')->assertStatus(409);
});

it('maps zod issues: only custom messages surface', function () {
    probe('z', function () {
        Z::strictObject(['a' => Z::string()])->parse(json_decode('{"a":1}'));
    });
    probe('z2', function () {
        Z::string()->refine(fn () => false, 'Özel mesaj.')->parse('x');
    });
    $this->post('/api/z')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
    $this->post('/api/z2')->assertStatus(400)->assertExactJson(['error' => 'Özel mesaj.']);
});

it('prefers the locale cookie, then Accept-Language', function () {
    probe('l', fn () => throw new HttpError(401, 'Giriş gerekli.'));
    expect($this->post('/api/l')->json('error'))->toBe('Giriş gerekli.');
    expect($this->flushHeaders()->withHeader('Accept-Language', 'en-GB,en;q=0.9')->post('/api/l')->json('error'))->not->toBe('Giriş gerekli.');
    expect($this->flushHeaders()->withCredentials()->withUnencryptedCookie('otosanayide-locale', 'tr')->withHeader('Accept-Language', 'en')->post('/api/l')->json('error'))->toBe('Giriş gerekli.');
    expect($this->flushHeaders()->withCredentials()->withUnencryptedCookie('otosanayide-locale', 'en')->withHeader('Accept-Language', 'tr')->post('/api/l')->json('error'))->not->toBe('Giriş gerekli.');
});

it('enforces the 20000 byte body limit and reads exactly up to it', function () {
    probe('b', fn (Request $r) => response()->json(['n' => strlen(Body::bytes($r, 20000))]));
    rawPost($this, '/api/b', str_repeat('a', 20000))->assertOk()->assertExactJson(['n' => 20000]);
    rawPost($this, '/api/b', str_repeat('a', 20001))->assertStatus(413)->assertExactJson(['error' => 'İstek çok büyük.']);
    rawPost($this, '/api/b', 'a', ['HTTP_CONTENT_LENGTH' => '999999'])->assertStatus(413);
});

it('rejects invalid JSON with 400 and keeps object/array distinct', function () {
    probe('j', fn (Request $r) => response()->json(['o' => Body::json($r) instanceof stdClass]));
    rawPost($this, '/api/j', '{oops')->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    rawPost($this, '/api/j', '{}')->assertExactJson(['o' => true]);
    rawPost($this, '/api/j', '[]')->assertExactJson(['o' => false]);
});

it('limits to 60 per minute per user, isolated per user', function () {
    probe('r', function () {
        RateLimit::consume('u1');

        return response()->json(['ok' => true]);
    });
    foreach (range(1, 60) as $_) {
        $this->post('/api/r')->assertOk();
    }
    $this->post('/api/r')->assertStatus(429)->assertExactJson(['error' => 'Çok fazla işlem. Bir dakika sonra yeniden deneyin.']);
    RateLimit::consume('u2');
    $this->assertTrue(true);
});

it('origin mobile mode allows native, web, mobile origins and echoes CORS', function () {
    probe('o', fn () => response()->json(['ok' => true]), ['origin:mobile']);
    $none = $this->post('/api/o')->assertOk();
    expect($none->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    $web = $this->flushHeaders()->withHeader('Origin', 'https://otosanayide.test')->post('/api/o')->assertOk();
    expect($web->headers->get('Access-Control-Allow-Origin'))->toBe('https://otosanayide.test');
    expect($web->headers->get('Vary'))->toBe('Origin');
    $this->flushHeaders()->withHeader('Origin', 'https://m.otosanayide.test')->post('/api/o')->assertOk();
    $this->flushHeaders()->withHeader('Origin', 'https://evil.test')->post('/api/o')->assertStatus(403)->assertExactJson(['error' => 'Bu işlem için yetkiniz yok.']);
});

it('ignores a spoofed Host header when checking origin', function () {
    probe('o', fn () => response()->json(['ok' => true]), ['origin:mobile']);
    $this->withHeaders(['Host' => 'evil.test', 'Origin' => 'https://evil.test'])->post('https://evil.test/api/o')->assertStatus(403);
});

it('origin web mode refuses the mobile origin', function () {
    probe('o', fn () => response()->json(['ok' => true]), ['origin:web']);
    $this->flushHeaders()->withHeader('Origin', 'https://m.otosanayide.test')->post('/api/o')->assertStatus(403);
    $this->flushHeaders()->post('/api/o')->assertOk();
});

it('origin exact mode requires the header; present mode allows absence', function () {
    probe('e', fn () => response()->json([]), ['origin:exact']);
    probe('p', fn () => response()->json([]), ['origin:present']);
    $this->flushHeaders()->post('/api/e')->assertStatus(403);
    $this->flushHeaders()->withHeader('Origin', 'https://otosanayide.test')->post('/api/e')->assertOk();
    $this->flushHeaders()->post('/api/p')->assertOk();
    $this->flushHeaders()->withHeader('Origin', 'https://evil.test')->post('/api/p')->assertStatus(403);
});

it('fails closed when PUBLIC_WEB_ORIGIN is missing', function () {
    config(['otosanayide.public_web_origin' => null]);
    probe('o', fn () => response()->json([]), ['origin:mobile']);
    $this->post('/api/o')->assertStatus(500);
});

it('feature flags default off', function () {
    foreach (config('otosanayide.flags') as $on) {
        expect($on)->toBeFalse();
    }
});
