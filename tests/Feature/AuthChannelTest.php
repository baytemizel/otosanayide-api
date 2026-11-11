<?php

use App\Domain\Auth\AuthClient;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\IssuedSession;
use App\Exceptions\HttpError;
use App\Jobs\SendAuthEmail;
use Illuminate\Testing\TestResponse;

// Sozlesme Eki v1: native (Origin yok) govdede yenileme jetonu, web/portal origin'e gore ayri cerez.
// Veritabani yok: AuthService kapsayicida AuthService'in aile/dondurme kurallarini taklit eden sahte alt sinifla degisir.
// Gercek AuthService::refresh aile iptali K177'de MySQL'de dogrulandi ve bu degisiklikte degismedi.

final class ChannelFakeAuth extends AuthService
{
    /** @var array<string,array{family:string,revoked:bool}> */
    public array $tokens = [];

    /** @var list<array{string,?string}> */
    public array $links = [];

    private int $n = 0;

    public function __construct()
    {
        parent::__construct(null);
    }

    public function login(string $email, string $password): array
    {
        if ($password !== 'correct-horse-1') {
            throw new HttpError(401, 'E-posta veya şifre hatalı.');
        }
        if ($email === 'mfa@example.com') {
            return ['session' => null, 'challenge' => 'challenge-1'];
        }

        return ['session' => $this->issueFake('family-'.(++$this->n)), 'challenge' => null];
    }

    public function completeMfa(string $challenge, string $code): IssuedSession
    {
        if ($challenge !== 'challenge-1' || $code !== '123456') {
            throw new HttpError(401, 'Doğrulama kodu hatalı veya süresi dolmuş.');
        }

        return $this->issueFake('family-mfa');
    }

    public function refresh(string $raw): IssuedSession
    {
        $row = $this->tokens[$raw] ?? null;
        if ($row === null) {
            throw new HttpError(401, 'Oturum geçersiz veya süresi dolmuş.');
        }
        if ($row['revoked']) {
            $this->revokeFamilyFake($row['family']);
            throw new HttpError(401, 'Oturum geçersiz veya süresi dolmuş.');
        }
        $this->tokens[$raw]['revoked'] = true;

        return $this->issueFake($row['family']);
    }

    public function logout(?string $raw): void
    {
        if ($raw !== null && isset($this->tokens[$raw])) {
            $this->revokeFamilyFake($this->tokens[$raw]['family']);
        }
    }

    public function register(string $email, string $password, ?string $linkOrigin = null): void
    {
        $this->links[] = ['register', $linkOrigin];
    }

    public function forgot(string $email, ?string $linkOrigin = null): void
    {
        $this->links[] = ['forgot', $linkOrigin];
    }

    private function issueFake(string $family): IssuedSession
    {
        $raw = 'refresh-'.$family.'-'.count($this->tokens);
        $this->tokens[$raw] = ['family' => $family, 'revoked' => false];

        return new IssuedSession('1|access-'.$raw, 900, $raw, 2592000, 'uid-1', 'u@example.com', true);
    }

    private function revokeFamilyFake(string $family): void
    {
        foreach ($this->tokens as $k => $t) {
            if ($t['family'] === $family) {
                $this->tokens[$k]['revoked'] = true;
            }
        }
    }
}

const ACH_WEB = 'https://otosanayide.test';
const ACH_PORTAL = 'https://portal.otosanayide.test';

beforeEach(function () {
    config(['otosanayide.portal_web_origin' => ACH_PORTAL]);
    $this->fake = new ChannelFakeAuth;
    $this->app->bind(AuthService::class, fn () => $this->fake);
});

function achNative(string $platform = 'ios', string $version = '1.4.0'): array
{
    return ['HTTP_X_CLIENT_PLATFORM' => $platform, 'HTTP_X_CLIENT_VERSION' => $version, 'HTTP_ACCEPT_LANGUAGE' => 'tr'];
}

function achCall($test, string $uri, array $server, string $body = '{}', array $cookies = []): TestResponse
{
    return $test->call('POST', $uri, [], $cookies, [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => ''], $body);
}

function achLoginBody(): string
{
    return '{"email":"u@example.com","password":"correct-horse-1"}';
}

it('returns the refresh token in the body to a native client and sets no cookie', function (string $platform) {
    $r = achCall($this, '/api/auth/login', achNative($platform), achLoginBody())->assertOk();
    expect(array_keys($r->json()))->toBe(['accessToken', 'expiresIn', 'refreshToken', 'user'])
        ->and($r->json('refreshToken'))->toBe('refresh-family-1-0')
        ->and($r->json('user'))->toBe(['uid' => 'uid-1', 'email' => 'u@example.com', 'emailVerified' => true])
        ->and($r->headers->getCookies())->toBe([])
        ->and($r->headers->get('Cache-Control'))->toBe('private, no-store')
        ->and($r->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with(['ios', 'android']);

it('rotates native refresh tokens and revokes the whole family on reuse', function () {
    $first = achCall($this, '/api/auth/login', achNative(), achLoginBody())->json('refreshToken');
    $second = achCall($this, '/api/auth/refresh', achNative(), json_encode(['refreshToken' => $first]))->assertOk();
    $next = $second->json('refreshToken');
    expect($next)->not->toBe($first)->and($second->headers->getCookies())->toBe([]);

    // Eski jeton tekrar: 401 ve aile iptal, yeni jeton da artik gecersiz.
    achCall($this, '/api/auth/refresh', achNative(), json_encode(['refreshToken' => $first]))->assertStatus(401)->assertExactJson(['error' => 'Oturum geçersiz veya süresi dolmuş.']);
    achCall($this, '/api/auth/refresh', achNative(), json_encode(['refreshToken' => $next]))->assertStatus(401);
});

it('logs a native client out from the body token without touching cookies', function () {
    $token = achCall($this, '/api/auth/login', achNative('android'), achLoginBody())->json('refreshToken');
    $r = achCall($this, '/api/auth/logout', achNative('android'), json_encode(['refreshToken' => $token]))->assertStatus(204);
    expect($r->headers->getCookies())->toBe([]);
    achCall($this, '/api/auth/refresh', achNative('android'), json_encode(['refreshToken' => $token]))->assertStatus(401);
});

it('completes MFA for a native client with the token in the body', function () {
    achCall($this, '/api/auth/login', achNative(), '{"email":"mfa@example.com","password":"correct-horse-1"}')->assertOk()->assertExactJson(['mfaRequired' => true, 'challenge' => 'challenge-1']);
    $r = achCall($this, '/api/auth/mfa/verify', achNative(), '{"challenge":"challenge-1","code":"123456"}')->assertOk();
    expect($r->json('refreshToken'))->toBe('refresh-family-mfa-0')->and($r->headers->getCookies())->toBe([]);
});

it('validates the native refresh and logout body', function () {
    achCall($this, '/api/auth/refresh', achNative(), '{}')->assertStatus(400)->assertExactJson(['error' => 'Bilgileri kontrol edin.']);
    achCall($this, '/api/auth/refresh', achNative(), '{"refreshToken":"x","extra":1}')->assertStatus(400);
    achCall($this, '/api/auth/refresh', achNative(), '{"refreshToken":"'.str_repeat('a', 257).'"}')->assertStatus(400);
    $this->call('POST', '/api/auth/refresh', [], [], [], achNative() + ['CONTENT_TYPE' => 'text/plain'], '{"refreshToken":"x"}')->assertStatus(415);
    achCall($this, '/api/auth/logout', achNative(), '{}')->assertStatus(400);
    // Native istek cerezi okumaz.
    achCall($this, '/api/auth/refresh', achNative(), '{"refreshToken":"bilinmeyen"}', [AuthClient::WEB_COOKIE => 'refresh-family-1-0'])->assertStatus(401);
});

it('refuses an origin-less session request without a valid client identity', function (array $server) {
    foreach (['/api/auth/login', '/api/auth/mfa/verify', '/api/auth/refresh', '/api/auth/logout'] as $uri) {
        achCall($this, $uri, $server, achLoginBody())->assertStatus(400)->assertExactJson(['error' => 'Geçersiz istek.']);
    }
})->with([
    'no headers' => [[]],
    'web platform' => [['HTTP_X_CLIENT_PLATFORM' => 'web', 'HTTP_X_CLIENT_VERSION' => '1.0.0']],
    'upper case' => [['HTTP_X_CLIENT_PLATFORM' => 'IOS', 'HTTP_X_CLIENT_VERSION' => '1.0.0']],
    'no version' => [['HTTP_X_CLIENT_PLATFORM' => 'ios']],
    'bad version' => [['HTTP_X_CLIENT_PLATFORM' => 'android', 'HTTP_X_CLIENT_VERSION' => 'v1']],
]);

it('never puts the refresh token in the body when an Origin is present', function (string $origin, string $cookie) {
    // Native basliklari tasisa bile tarayici kanali gecerlidir.
    $r = achCall($this, '/api/auth/login', achNative() + ['HTTP_ORIGIN' => $origin], achLoginBody())->assertOk();
    expect($r->json())->not->toHaveKey('refreshToken');
    $set = $r->headers->getCookies();
    expect($set)->toHaveCount(1)
        ->and($set[0]->getName())->toBe($cookie)
        ->and($set[0]->getValue())->toBe('refresh-family-1-0')
        ->and($set[0]->isHttpOnly())->toBeTrue()
        ->and($set[0]->isSecure())->toBeTrue()
        ->and($set[0]->getSameSite())->toBe('strict')
        ->and($set[0]->getPath())->toBe('/api/auth')
        ->and($r->headers->get('Access-Control-Allow-Origin'))->toBe($origin)
        ->and($r->headers->get('Access-Control-Allow-Credentials'))->toBe('true');

    // Govdedeki jeton yok sayilir; yalniz origin'e ait cerez okunur.
    achCall($this, '/api/auth/refresh', ['HTTP_ORIGIN' => $origin], '{"refreshToken":"refresh-family-1-0"}')->assertStatus(401);
    $refreshed = achCall($this, '/api/auth/refresh', ['HTTP_ORIGIN' => $origin], '', [$cookie => 'refresh-family-1-0'])->assertOk();
    expect($refreshed->json())->not->toHaveKey('refreshToken')
        ->and($refreshed->headers->getCookies()[0]->getName())->toBe($cookie);
})->with([
    'web' => [ACH_WEB, 'otosanayide_refresh'],
    'portal' => [ACH_PORTAL, 'otosanayide_portal_refresh'],
]);

it('keeps web and portal refresh cookies apart', function () {
    achCall($this, '/api/auth/login', ['HTTP_ORIGIN' => ACH_WEB], achLoginBody())->assertOk();
    // Portal, web cerezini kullanamaz; web de portal cerezini.
    achCall($this, '/api/auth/refresh', ['HTTP_ORIGIN' => ACH_PORTAL], '', [AuthClient::WEB_COOKIE => 'refresh-family-1-0'])->assertStatus(401);
    achCall($this, '/api/auth/refresh', ['HTTP_ORIGIN' => ACH_WEB], '', [AuthClient::PORTAL_COOKIE => 'refresh-family-1-0'])->assertStatus(401);

    $logout = achCall($this, '/api/auth/logout', ['HTTP_ORIGIN' => ACH_PORTAL], '', [AuthClient::PORTAL_COOKIE => 'x'])->assertStatus(204);
    $cleared = $logout->headers->getCookies()[0];
    expect($cleared->getName())->toBe(AuthClient::PORTAL_COOKIE)->and($cleared->getValue())->toBe('')->and($cleared->getExpiresTime())->toBeLessThan(time());
});

it('refuses auth from an unconfigured or foreign portal', function () {
    config(['otosanayide.portal_web_origin' => null]);
    achCall($this, '/api/auth/login', ['HTTP_ORIGIN' => ACH_PORTAL], achLoginBody())->assertStatus(403);
    config(['otosanayide.portal_web_origin' => 'http://portal.otosanayide.test']); // https zorunlu
    achCall($this, '/api/auth/login', ['HTTP_ORIGIN' => 'http://portal.otosanayide.test'], achLoginBody())->assertStatus(403);
    config(['otosanayide.portal_web_origin' => ACH_PORTAL.'/']); // tam origin degil
    achCall($this, '/api/auth/login', ['HTTP_ORIGIN' => ACH_PORTAL], achLoginBody())->assertStatus(403);
});

it('builds e-mail links on the origin that started the request', function () {
    achCall($this, '/api/auth/password/forgot', ['HTTP_ORIGIN' => ACH_PORTAL], '{"email":"u@example.com"}')->assertStatus(202);
    achCall($this, '/api/auth/password/forgot', ['HTTP_ORIGIN' => ACH_WEB], '{"email":"u@example.com"}')->assertStatus(202);
    achCall($this, '/api/auth/register', achNative(), '{"email":"u@example.com","password":"long-enough-1"}')->assertStatus(202);
    expect($this->fake->links)->toBe([['forgot', ACH_PORTAL], ['forgot', ACH_WEB], ['register', ACH_WEB]]);
});

it('sends the portal link only when the queued origin is still the configured portal', function () {
    $sent = function (?string $origin): string {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $transport->flush();
        (new SendAuthEmail('u@example.com', 'reset_password', 'tok', 'tr', $origin))->handle();

        return $transport->messages()->first()->getOriginalMessage()->getTextBody();
    };
    expect($sent(ACH_PORTAL))->toBe(ACH_PORTAL.'/giris/sifre-sifirla?token=tok')
        ->and($sent(null))->toBe(ACH_WEB.'/giris/sifre-sifirla?token=tok')
        ->and($sent('https://evil.test'))->toBe(ACH_WEB.'/giris/sifre-sifirla?token=tok');
    config(['otosanayide.portal_web_origin' => null]);
    expect($sent(ACH_PORTAL))->toBe(ACH_WEB.'/giris/sifre-sifirla?token=tok');
});
