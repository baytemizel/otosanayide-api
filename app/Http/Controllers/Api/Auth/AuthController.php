<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\ActorResolver;
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

/** Yeni uclar: kaynakta Firebase Auth istemcisi vardi; sozlesme docs/AUTH_API.md. */
final class AuthController
{
    public const COOKIE = 'otosanayide_refresh';

    private const MIN_PASSWORD = 10;

    private const MAX_PASSWORD = 1024;

    public function register(Request $request): JsonResponse
    {
        $input = $this->input($request, ['email' => $this->email(), 'password' => $this->newPassword()]);
        Throttle::hit('register-ip', (string) $request->ip(), 10, 3600);
        $this->service($request)->register($this->normalize($input['email']), $input['password']);

        return Api::json(['ok' => true], 202);
    }

    public function login(Request $request): JsonResponse
    {
        $input = $this->input($request, ['email' => $this->email(), 'password' => Z::string()->min(1)->max(self::MAX_PASSWORD)]);
        $email = $this->normalize($input['email']);
        Throttle::hit('login-email', $email, 10, 900);
        Throttle::hit('login-ip', (string) $request->ip(), 60, 900);
        $result = $this->service($request)->login($email, $input['password']);
        if ($result['session'] === null) {
            return Api::json(['mfaRequired' => true, 'challenge' => $result['challenge']]);
        }

        return $this->session($result['session']);
    }

    public function mfaVerify(Request $request): JsonResponse
    {
        $input = $this->input($request, ['challenge' => Z::string()->min(1)->max(128), 'code' => Z::string()->max(16)]);
        Throttle::hit('mfa-ip', (string) $request->ip(), 30, 900);

        return $this->session($this->service($request)->completeMfa($input['challenge'], $input['code']));
    }

    public function refresh(Request $request): JsonResponse
    {
        $raw = $request->cookies->get(self::COOKIE);
        if (! is_string($raw) || $raw === '') {
            throw new HttpError(401, 'Oturum geçersiz veya süresi dolmuş.');
        }
        Throttle::hit('refresh-ip', (string) $request->ip(), 120, 60);

        return $this->session($this->service($request)->refresh($raw));
    }

    public function logout(Request $request): Response
    {
        $raw = $request->cookies->get(self::COOKIE);
        $this->service($request)->logout(is_string($raw) ? $raw : null);
        $response = Api::empty(204);
        $response->headers->setCookie($this->cookie('', -1));

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
        $this->service($request)->resendVerification($actor);

        return Api::json(['ok' => true], 202);
    }

    public function forgot(Request $request): JsonResponse
    {
        $input = $this->input($request, ['email' => $this->email()]);
        $email = $this->normalize($input['email']);
        Throttle::hit('forgot-email', $email, 3, 3600);
        Throttle::hit('forgot-ip', (string) $request->ip(), 20, 3600);
        $this->service($request)->forgot($email);

        return Api::json(['ok' => true], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $input = $this->input($request, ['token' => Z::string()->min(1)->max(128), 'password' => $this->newPassword()]);
        Throttle::hit('reset-ip', (string) $request->ip(), 20, 3600);
        $this->service($request)->resetPassword($input['token'], $input['password']);

        return Api::json(['ok' => true]);
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

    private function service(Request $request): AuthService
    {
        return new AuthService($request->ip());
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

    private function session(IssuedSession $s): JsonResponse
    {
        $response = Api::json([
            'accessToken' => $s->accessToken,
            'expiresIn' => $s->expiresIn,
            'user' => ['uid' => $s->uid, 'email' => $s->email, 'emailVerified' => $s->emailVerified],
        ]);
        $response->headers->setCookie($this->cookie($s->refreshToken, $s->refreshMaxAge));

        return $response;
    }

    private function cookie(string $value, int $maxAge): Cookie
    {
        return Cookie::create(self::COOKIE, $value, $maxAge > 0 ? time() + $maxAge : 1, '/api/auth', null, true, true, false, Cookie::SAMESITE_STRICT);
    }
}
