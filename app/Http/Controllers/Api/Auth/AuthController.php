<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\ActorResolver;
use App\Domain\Auth\AuthClient;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\IssuedSession;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use App\Support\Throttle;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/** Yeni uclar: kaynakta Firebase Auth istemcisi vardi; sozlesme docs/AUTH_API.md (Sozlesme Eki v1 dahil). */
final class AuthController
{
    public const COOKIE = AuthClient::WEB_COOKIE;

    private const MIN_PASSWORD = 10;

    private const MAX_PASSWORD = 1024;

    public function register(Request $request): JsonResponse
    {
        $input = $this->input($request, ['email' => $this->email(), 'password' => $this->newPassword()]);
        Throttle::hit('register-ip', (string) $request->ip(), 10, 3600);
        $this->service($request)->register($this->normalize($input['email']), $input['password'], AuthClient::linkOrigin($request));

        return Api::json(['ok' => true], 202);
    }

    public function login(Request $request): JsonResponse
    {
        $client = AuthClient::of($request);
        $input = $this->input($request, ['email' => $this->email(), 'password' => Z::string()->min(1)->max(self::MAX_PASSWORD)]);
        $email = $this->normalize($input['email']);
        Throttle::hit('login-email', $email, 10, 900);
        Throttle::hit('login-ip', (string) $request->ip(), 60, 900);
        $result = $this->service($request)->login($email, $input['password']);
        if ($result['session'] === null) {
            return Api::json(['mfaRequired' => true, 'challenge' => $result['challenge']]);
        }

        return $this->session($result['session'], $client);
    }

    public function mfaVerify(Request $request): JsonResponse
    {
        $client = AuthClient::of($request);
        $input = $this->input($request, ['challenge' => Z::string()->min(1)->max(128), 'code' => Z::string()->max(16)]);
        Throttle::hit('mfa-ip', (string) $request->ip(), 30, 900);

        return $this->session($this->service($request)->completeMfa($input['challenge'], $input['code']), $client);
    }

    public function refresh(Request $request): JsonResponse
    {
        $client = AuthClient::of($request);
        $raw = $this->refreshToken($request, $client);
        if ($raw === null) {
            throw new HttpError(401, 'Oturum geçersiz veya süresi dolmuş.');
        }
        Throttle::hit('refresh-ip', (string) $request->ip(), 120, 60);

        return $this->session($this->service($request)->refresh($raw), $client);
    }

    public function logout(Request $request): Response
    {
        $client = AuthClient::of($request);
        $this->service($request)->logout($this->refreshToken($request, $client));
        $response = Api::empty(204);
        if (! $client->native()) {
            $response->headers->setCookie($this->cookie((string) $client->cookie(), '', -1));
        }

        return $response;
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $input = $this->input($request, ['token' => Z::string()->min(1)->max(128)]);
        Throttle::hit('verify-ip', (string) $request->ip(), 20, 3600);
        $this->service($request)->verifyEmail($input['token']);

        return Api::json(['ok' => true]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $actor = ActorResolver::resolve($request);
        Throttle::hit('resend', $actor->uid, 5, 3600);
        $this->service($request)->resendVerification($actor, AuthClient::linkOrigin($request));

        return Api::json(['ok' => true], 202);
    }

    public function forgot(Request $request): JsonResponse
    {
        $input = $this->input($request, ['email' => $this->email()]);
        $email = $this->normalize($input['email']);
        Throttle::hit('forgot-email', $email, 3, 3600);
        Throttle::hit('forgot-ip', (string) $request->ip(), 20, 3600);
        $this->service($request)->forgot($email, AuthClient::linkOrigin($request));

        return Api::json(['ok' => true], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $input = $this->input($request, ['token' => Z::string()->min(1)->max(128), 'password' => $this->newPassword()]);
        Throttle::hit('reset-ip', (string) $request->ip(), 20, 3600);
        $this->service($request)->resetPassword($input['token'], $input['password']);

        return Api::json(['ok' => true]);
    }

    /** web-01: GET /api/auth/mfa. */
    public function mfaStatus(Request $request): JsonResponse
    {
        $actor = ActorResolver::resolve($request);
        Throttle::hit('mfa-status', $actor->uid, 60, 60);

        return Api::json($this->service($request)->mfaStatus($actor));
    }

    /** web-02: POST /api/auth/password/change. Oturum kanali login ile ayni (cerez veya native govde). */
    public function changePassword(Request $request): JsonResponse
    {
        $client = AuthClient::of($request);
        if (! str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            throw new HttpError(415, 'Geçersiz istek.');
        }
        $actor = ActorResolver::resolve($request);
        Throttle::hit('password-change', $actor->uid, 5, 3600);
        $input = $this->input($request, ['currentPassword' => Z::string()->min(1)->max(self::MAX_PASSWORD), 'newPassword' => $this->newPassword(), 'code' => Z::string()->max(16)->optional()]);

        return $this->session($this->service($request)->changePassword($actor, $input['currentPassword'], $input['newPassword'], $input['code'] ?? null), $client);
    }

    /** web-03: POST /api/auth/email/change. Hesap yeni adres onaylanana kadar degismez. */
    public function changeEmail(Request $request): JsonResponse
    {
        if (! str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            throw new HttpError(415, 'Geçersiz istek.');
        }
        $actor = ActorResolver::resolve($request);
        Throttle::hit('email-change', $actor->uid, 3, 3600);
        $input = $this->input($request, ['newEmail' => $this->email(), 'password' => Z::string()->min(1)->max(self::MAX_PASSWORD), 'code' => Z::string()->max(16)->optional()]);
        $this->service($request)->requestEmailChange($actor, $this->normalize($input['newEmail']), $input['password'], $input['code'] ?? null, AuthClient::linkOrigin($request));

        return Api::json(['ok' => true], 202);
    }

    public function totpEnroll(Request $request): JsonResponse
    {
        $actor = ActorResolver::resolve($request);
        if (! $actor->emailVerified) {
            throw new HttpError(403, 'Önce e-posta adresini doğrula.');
        }
        Throttle::hit('totp', $actor->uid, 20, 3600);

        return Api::json($this->service($request)->enrollTotp($actor), 201);
    }

    public function totpConfirm(Request $request): JsonResponse
    {
        $actor = ActorResolver::resolve($request);
        if (! $actor->emailVerified) {
            throw new HttpError(403, 'Önce e-posta adresini doğrula.');
        }
        Throttle::hit('totp', $actor->uid, 20, 3600);
        $input = $this->input($request, ['code' => Z::string()->max(16)]);
        $this->service($request)->confirmTotp($actor, $input['code']);

        return Api::json(['ok' => true]);
    }

    public function totpDisable(Request $request): JsonResponse
    {
        $actor = ActorResolver::resolve($request);
        Throttle::hit('totp', $actor->uid, 20, 3600);
        $input = $this->input($request, ['code' => Z::string()->max(16)]);
        $this->service($request)->disableTotp($actor, $input['code']);

        return Api::json(['ok' => true]);
    }

    /** Kapsayicidan: baglantisiz testler sahte servis baglayabilir. */
    private function service(Request $request): AuthService
    {
        return app()->make(AuthService::class, ['ip' => $request->ip()]);
    }

    /** Native: zorunlu JSON govde {"refreshToken"}; tarayici: yalniz origin'e ait cerez, govde okunmaz. */
    private function refreshToken(Request $request, AuthClient $client): ?string
    {
        if ($client->native()) {
            $raw = $this->input($request, ['refreshToken' => Z::string()->min(1)->max(256)])['refreshToken'];

            return is_string($raw) ? $raw : null;
        }
        $raw = $request->cookies->get((string) $client->cookie());

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function email(): Z
    {
        return Z::string()->trim()->max(254)->email();
    }

    private function newPassword(): Z
    {
        return Z::string()->min(self::MIN_PASSWORD)->max(self::MAX_PASSWORD);
    }

    /** Kucuk harf + NFC (0002_auth.sql notu). */
    private function normalize(string $email): string
    {
        return (string) normalizer_normalize(mb_strtolower($email, 'UTF-8'), \Normalizer::FORM_C);
    }

    /**
     * @param  array<string,Z>  $shape
     * @return array<string,mixed>
     */
    private function input(Request $request, array $shape): array
    {
        if (! str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            throw new HttpError(415, 'Geçersiz istek.');
        }

        return Z::strictObject($shape)->parse(Body::json($request));
    }

    private function session(IssuedSession $s, AuthClient $client): JsonResponse
    {
        $body = ['accessToken' => $s->accessToken, 'expiresIn' => $s->expiresIn];
        if ($client->native()) {
            // Yalniz Origin'siz native istemci: tarayici akisinda jeton govdeye asla girmez.
            $body['refreshToken'] = $s->refreshToken;
        }
        $body['user'] = ['uid' => $s->uid, 'email' => $s->email, 'emailVerified' => $s->emailVerified];
        $response = Api::json($body);
        if (! $client->native()) {
            $response->headers->setCookie($this->cookie((string) $client->cookie(), $s->refreshToken, $s->refreshMaxAge));
        }

        return $response;
    }

    private function cookie(string $name, string $value, int $maxAge): Cookie
    {
        return Cookie::create($name, $value, $maxAge > 0 ? time() + $maxAge : 1, '/api/auth', null, true, true, false, Cookie::SAMESITE_STRICT);
    }
}
