<?php

namespace App\Domain\Auth;

use App\Exceptions\HttpError;
use App\Jobs\SendAuthEmail;
use App\Models\Account;
use App\Support\Tx;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use stdClass;

/** Firebase Auth yerine MySQL'de kendi giris sistemi (K171): sozlesme db/mysql/ADAPTER_CONTRACT_TR.md. */
final class AuthService
{
    public const ACCESS_TTL_SECONDS = 900;

    public const REFRESH_TTL_SECONDS = 2592000;

    private const VERIFY_TTL_SECONDS = 86400;

    private const RESET_TTL_SECONDS = 3600;

    private const MFA_CHALLENGE_TTL_SECONDS = 300;

    private const MFA_MAX_ATTEMPTS = 5;

    /** Var olmayan hesapta da ayni maliyette dogrulama yapilsin; parametreler config/hashing.php ile aynidir. */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$VTZGYkpqd0hISEN1MWJQNw$0EdfUhE7acbqW9b3KRnA7R7mHjRG/ZZxWdNMZvtM/qY';

    public function __construct(private readonly ?string $ip = null) {}

    public function register(string $email, string $password): void
    {
        $hash = Hash::make($password);
        $now = CarbonImmutable::now('UTC');
        try {
            $token = Tx::run(function () use ($email, $hash, $now): ?array {
                if (DB::table('auth_accounts')->where('email', $email)->lockForUpdate()->exists()) {
                    return null;
                }
                $uid = Str::random(28);
                DB::table('auth_accounts')->insert([
                    'id' => $uid, 'email' => $email, 'password_hash' => $hash,
                    'password_changed_at' => $this->ms($now), 'tokens_valid_after' => $this->ms($now),
                ]);
                $this->event($uid, 'register');

                return [$uid, $this->newEmailToken($uid, 'verify_email', self::VERIFY_TTL_SECONDS)];
            });
        } catch (UniqueConstraintViolationException) {
            $token = null;
        }
        // Yanit iki durumda da aynidir; var olan hesaba yalniz bilgilendirme gider.
        SendAuthEmail::dispatch($email, $token === null ? 'account_exists' : 'verify_email', $token[1] ?? null, App::getLocale());
    }

    /** @return array{session:?IssuedSession,challenge:?string} */
    public function login(string $email, string $password): array
    {
        $account = DB::table('auth_accounts')->where('email', $email)->first();
        $valid = Hash::check($password, $account->password_hash ?? self::DUMMY_HASH);
        if ($account === null || ! $valid || $account->status === 'disabled') {
            $this->event($account?->id, 'login_failed');
            throw new HttpError(401, 'E-posta veya şifre hatalı.');
        }
        if (Hash::needsRehash($account->password_hash)) {
            DB::table('auth_accounts')->where('id', $account->id)->update(['password_hash' => Hash::make($password)]);
        }
        $hasFactor = DB::table('auth_mfa_factors')->where('account_id', $account->id)
            ->where('type', 'totp')->whereNotNull('verified_at')->whereNull('disabled_at')->exists();
        if ($hasFactor) {
            $raw = $this->token();
            DB::table('auth_mfa_challenges')->insert([
                'token_hash' => hash('sha256', $raw), 'account_id' => $account->id,
                'expires_at' => $this->ms(CarbonImmutable::now('UTC')->addSeconds(self::MFA_CHALLENGE_TTL_SECONDS)),
            ]);

            return ['session' => null, 'challenge' => $raw];
        }

        return ['session' => Tx::run(fn () => $this->issue($account, null, CarbonImmutable::now('UTC'), (string) Str::uuid())), 'challenge' => null];
    }

    public function completeMfa(string $challenge, string $code): IssuedSession
    {
        $failure = new HttpError(401, 'Doğrulama kodu hatalı veya süresi dolmuş.');
        $issued = Tx::run(function () use ($challenge, $code): ?IssuedSession {
            $row = DB::table('auth_mfa_challenges')->where('token_hash', hash('sha256', $challenge))->lockForUpdate()->first();
            if ($row === null || $row->consumed_at !== null || CarbonImmutable::parse($row->expires_at, 'UTC')->isPast()) {
                return null;
            }
            $factor = $this->activeFactor($row->account_id);
            $step = $factor === null ? null : Totp::matchStep(Crypt::decryptString($factor->secret_ciphertext), $code, time(), $factor->last_used_step === null ? null : (int) $factor->last_used_step);
            if ($step === null) {
                $attempts = (int) $row->attempts + 1;
                DB::table('auth_mfa_challenges')->where('token_hash', $row->token_hash)->update([
                    'attempts' => $attempts,
                    'consumed_at' => $attempts >= self::MFA_MAX_ATTEMPTS ? $this->ms(CarbonImmutable::now('UTC')) : null,
                ]);
                $this->event($row->account_id, 'mfa_failed');

                return null;
            }
            DB::table('auth_mfa_factors')->where('id', $factor->id)->update(['last_used_step' => $step]);
            DB::table('auth_mfa_challenges')->where('token_hash', $row->token_hash)->update(['consumed_at' => $this->ms(CarbonImmutable::now('UTC'))]);
            $account = DB::table('auth_accounts')->where('id', $row->account_id)->first();
            if ($account === null || $account->status === 'disabled') {
                return null;
            }

            return $this->issue($account, 'totp', CarbonImmutable::now('UTC'), (string) Str::uuid());
        });
        if ($issued === null) {
            throw $failure;
        }

        return $issued;
    }

    public function refresh(string $raw): IssuedSession
    {
        $hash = hash('sha256', $raw);
        $result = Tx::run(function () use ($hash): ?IssuedSession {
            $session = DB::table('auth_sessions')->where('refresh_token_hash', $hash)->lockForUpdate()->first();
            if ($session === null) {
                return null;
            }
            if ($session->revoked_at !== null) {
                // Tekrar kullanim: butun aile iptal; transaction commit olmali, bu yuzden throw yok.
                $this->revokeFamily($session->family_id);
                $this->event($session->account_id, 'refresh_reuse');

                return null;
            }
            $now = CarbonImmutable::now('UTC');
            if (CarbonImmutable::parse($session->expires_at, 'UTC')->lessThanOrEqualTo($now)) {
                DB::table('auth_sessions')->where('id', $session->id)->update(['revoked_at' => $this->ms($now)]);

                return null;
            }
            $account = DB::table('auth_accounts')->where('id', $session->account_id)->first();
            if ($account === null || $account->status === 'disabled') {
                return null;
            }
            $issued = $this->issue($account, $session->second_factor, CarbonImmutable::parse($session->auth_time, 'UTC'), $session->family_id, $session->id);
            DB::table('auth_sessions')->where('id', $session->id)->update(['last_used_at' => $this->ms($now)]);

            return $issued;
        });
        if ($result === null) {
            throw new HttpError(401, 'Oturum geçersiz veya süresi dolmuş.');
        }

        return $result;
    }

    public function logout(?string $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        Tx::run(function () use ($raw): void {
            $session = DB::table('auth_sessions')->where('refresh_token_hash', hash('sha256', $raw))->lockForUpdate()->first();
            if ($session !== null) {
                $this->revokeFamily($session->family_id);
                $this->event($session->account_id, 'logout');
            }
        });
    }

    public function verifyEmail(string $raw): void
    {
        $ok = Tx::run(function () use ($raw): bool {
            $row = $this->consumeEmailToken($raw, 'verify_email');
            if ($row === null) {
                return false;
            }
            DB::table('auth_accounts')->where('id', $row->account_id)->whereNull('email_verified_at')->update(['email_verified_at' => $this->ms(CarbonImmutable::now('UTC'))]);
            $this->event($row->account_id, 'email_verified');

            return true;
        });
        if (! $ok) {
            throw new HttpError(400, 'Bağlantı geçersiz veya süresi dolmuş.');
        }
    }

    public function resendVerification(Actor $actor): void
    {
        if ($actor->emailVerified) {
            return;
        }
        $token = Tx::run(fn () => $this->newEmailToken($actor->uid, 'verify_email', self::VERIFY_TTL_SECONDS));
        SendAuthEmail::dispatch($actor->email, 'verify_email', $token, App::getLocale());
    }

    public function forgot(string $email): void
    {
        $account = DB::table('auth_accounts')->where('email', $email)->where('status', '!=', 'disabled')->first();
        $token = $account === null ? null : Tx::run(fn () => $this->newEmailToken($account->id, 'reset_password', self::RESET_TTL_SECONDS));
        SendAuthEmail::dispatch($account === null ? null : $email, 'reset_password', $token, App::getLocale());
    }

    public function resetPassword(string $raw, string $password): void
    {
        $hash = Hash::make($password);
        $ok = Tx::run(function () use ($raw, $hash): bool {
            $row = $this->consumeEmailToken($raw, 'reset_password');
            if ($row === null) {
                return false;
            }
            $now = $this->ms(CarbonImmutable::now('UTC'));
            DB::table('auth_accounts')->where('id', $row->account_id)->update(['password_hash' => $hash, 'password_changed_at' => $now, 'tokens_valid_after' => $now]);
            DB::table('auth_email_tokens')->where('account_id', $row->account_id)->where('purpose', 'reset_password')->whereNull('consumed_at')->update(['consumed_at' => $now]);
            $this->revokeAll($row->account_id);
            $this->event($row->account_id, 'password_reset');

            return true;
        });
        if (! $ok) {
            throw new HttpError(400, 'Bağlantı geçersiz veya süresi dolmuş.');
        }
    }

    /** @return array{secret:string,otpauthUri:string} */
    public function enrollTotp(Actor $actor): array
    {
        $secret = Totp::newSecret();
        Tx::run(function () use ($actor, $secret): void {
            DB::table('auth_accounts')->where('id', $actor->uid)->lockForUpdate()->first();
            if ($this->activeFactor($actor->uid) !== null) {
                throw new HttpError(409, 'İki adımlı doğrulama zaten etkin.');
            }
            DB::table('auth_mfa_factors')->where('account_id', $actor->uid)->whereNull('verified_at')->delete();
            DB::table('auth_mfa_factors')->insert(['id' => (string) Str::uuid(), 'account_id' => $actor->uid, 'type' => 'totp', 'secret_ciphertext' => Crypt::encryptString($secret)]);
        });

        return ['secret' => $secret, 'otpauthUri' => Totp::uri($secret, $actor->email)];
    }

    public function confirmTotp(Actor $actor, string $code): void
    {
        Tx::run(function () use ($actor, $code): void {
            $factor = DB::table('auth_mfa_factors')->where('account_id', $actor->uid)->where('type', 'totp')->whereNull('verified_at')->whereNull('disabled_at')->lockForUpdate()->first();
            if ($factor === null) {
                throw new HttpError(400, 'Doğrulama kodu hatalı veya süresi dolmuş.');
            }
            $step = Totp::matchStep(Crypt::decryptString($factor->secret_ciphertext), $code, time(), null);
            if ($step === null) {
                throw new HttpError(400, 'Doğrulama kodu hatalı veya süresi dolmuş.');
            }
            DB::table('auth_mfa_factors')->where('id', $factor->id)->update(['verified_at' => $this->ms(CarbonImmutable::now('UTC')), 'last_used_step' => $step]);
            DB::table('auth_sessions')->where('id', $actor->sessionId)->update(['second_factor' => 'totp']);
            $this->event($actor->uid, 'mfa_enrolled');
        });
    }

    public function disableTotp(Actor $actor, string $code): void
    {
        Tx::run(function () use ($actor, $code): void {
            $factor = $this->activeFactor($actor->uid);
            if ($factor === null) {
                throw new HttpError(400, 'İki adımlı doğrulama etkin değil.');
            }
            $step = Totp::matchStep(Crypt::decryptString($factor->secret_ciphertext), $code, time(), $factor->last_used_step === null ? null : (int) $factor->last_used_step);
            if ($step === null) {
                throw new HttpError(400, 'Doğrulama kodu hatalı veya süresi dolmuş.');
            }
            $now = $this->ms(CarbonImmutable::now('UTC'));
            DB::table('auth_mfa_factors')->where('id', $factor->id)->update(['disabled_at' => $now, 'last_used_step' => $step]);
            // Eski ikinci faktor bilgisi tasinmasin: mevcut oturumlar iptal edilir.
            DB::table('auth_accounts')->where('id', $actor->uid)->update(['tokens_valid_after' => $now]);
            $this->revokeAll($actor->uid);
            $this->event($actor->uid, 'mfa_disabled');
        });
    }

    private function issue(stdClass $account, ?string $secondFactor, CarbonImmutable $authTime, string $family, ?string $previous = null): IssuedSession
    {
        $now = CarbonImmutable::now('UTC');
        $raw = $this->token();
        $id = (string) Str::uuid();
        DB::table('auth_sessions')->insert([
            'id' => $id, 'account_id' => $account->id, 'family_id' => $family,
            'refresh_token_hash' => hash('sha256', $raw), 'second_factor' => $secondFactor,
            'auth_time' => $this->ms($authTime), 'expires_at' => $this->ms($now->addSeconds(self::REFRESH_TTL_SECONDS)),
        ]);
        if ($previous !== null) {
            DB::table('auth_sessions')->where('id', $previous)->update(['revoked_at' => $this->ms($now), 'replaced_by' => $id]);
            DB::table('personal_access_tokens')->where('session_id', $previous)->delete();
        }
        $plain = Str::random(48);
        $token = Account::query()->findOrFail($account->id)->tokens()->create([
            'name' => 'access', 'token' => hash('sha256', $plain), 'abilities' => ['*'],
            'expires_at' => $now->addSeconds(self::ACCESS_TTL_SECONDS), 'session_id' => $id,
        ]);
        $access = new NewAccessToken($token, $token->getKey().'|'.$plain);
        $this->event($account->id, $previous === null ? 'login' : 'refresh');

        return new IssuedSession($access->plainTextToken, self::ACCESS_TTL_SECONDS, $raw, self::REFRESH_TTL_SECONDS, $account->id, $account->email, $account->email_verified_at !== null);
    }

    private function revokeFamily(string $family): void
    {
        $now = $this->ms(CarbonImmutable::now('UTC'));
        $ids = DB::table('auth_sessions')->where('family_id', $family)->pluck('id');
        DB::table('auth_sessions')->where('family_id', $family)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        DB::table('personal_access_tokens')->whereIn('session_id', $ids)->delete();
    }

    private function revokeAll(string $accountId): void
    {
        $now = $this->ms(CarbonImmutable::now('UTC'));
        $ids = DB::table('auth_sessions')->where('account_id', $accountId)->pluck('id');
        DB::table('auth_sessions')->where('account_id', $accountId)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        DB::table('personal_access_tokens')->whereIn('session_id', $ids)->delete();
    }

    private function activeFactor(string $accountId): ?stdClass
    {
        return DB::table('auth_mfa_factors')->where('account_id', $accountId)->where('type', 'totp')
            ->whereNotNull('verified_at')->whereNull('disabled_at')->lockForUpdate()->first();
    }

    private function newEmailToken(string $accountId, string $purpose, int $ttl): string
    {
        $now = CarbonImmutable::now('UTC');
        DB::table('auth_email_tokens')->where('account_id', $accountId)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => $this->ms($now)]);
        $raw = $this->token();
        DB::table('auth_email_tokens')->insert(['token_hash' => hash('sha256', $raw), 'account_id' => $accountId, 'purpose' => $purpose, 'expires_at' => $this->ms($now->addSeconds($ttl))]);

        return $raw;
    }

    private function consumeEmailToken(string $raw, string $purpose): ?stdClass
    {
        $row = DB::table('auth_email_tokens')->where('token_hash', hash('sha256', $raw))->where('purpose', $purpose)->lockForUpdate()->first();
        if ($row === null || $row->consumed_at !== null || CarbonImmutable::parse($row->expires_at, 'UTC')->isPast()) {
            return null;
        }
        DB::table('auth_email_tokens')->where('token_hash', $row->token_hash)->update(['consumed_at' => $this->ms(CarbonImmutable::now('UTC'))]);

        return $row;
    }

    private function event(?string $accountId, string $event): void
    {
        DB::table('auth_events')->insert([
            'account_id' => $accountId, 'event' => $event,
            'ip_hash' => $this->ip === null ? null : hash_hmac('sha256', $this->ip, (string) config('app.key')),
        ]);
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function ms(CarbonImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.v');
    }
}
