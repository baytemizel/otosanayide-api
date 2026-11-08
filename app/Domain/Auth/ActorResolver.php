<?php

namespace App\Domain\Auth;

use App\Exceptions\HttpError;
use App\Models\AccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** actor(req) karsiligi: her istekte iptal/gecerlilik veritabanindan denetlenir (verifyIdToken(token,true)). */
final class ActorResolver
{
    public static function resolve(Request $request): Actor
    {
        if (preg_match('/^Bearer (.+)$/D', (string) $request->headers->get('Authorization'), $m) !== 1) {
            throw new HttpError(401, 'Giriş gerekli.');
        }
        $invalid = new HttpError(401, 'Oturum geçersiz veya süresi dolmuş.');
        $token = AccessToken::findToken($m[1]);
        if (! $token instanceof AccessToken || $token->tokenable_type !== 'account' || $token->expires_at === null || $token->expires_at->isPast()) {
            throw $invalid;
        }
        $row = DB::table('auth_accounts as a')
            ->join('auth_sessions as s', 's.account_id', '=', 'a.id')
            ->where('a.id', $token->tokenable_id)
            ->where('s.id', $token->getAttribute('session_id'))
            ->first(['a.id', 'a.email', 'a.email_verified_at', 'a.tokens_valid_after', 'a.status', 'a.is_admin', 'a.is_account_erasure_operator', 'a.is_brand_finance', 's.second_factor', 's.auth_time', 's.revoked_at', 's.expires_at']);
        if ($row === null || $row->status === 'disabled' || $row->revoked_at !== null) {
            throw $invalid;
        }
        $issued = CarbonImmutable::parse($token->created_at);
        if ($issued->lessThan(CarbonImmutable::parse($row->tokens_valid_after))) {
            throw $invalid;
        }

        return new Actor(
            uid: $row->id,
            email: $row->email,
            emailVerified: $row->email_verified_at !== null,
            admin: (bool) $row->is_admin,
            accountErasureOperator: (bool) $row->is_account_erasure_operator,
            brandFinance: (bool) $row->is_brand_finance,
            secondFactor: $row->second_factor,
            authTime: CarbonImmutable::parse($row->auth_time)->getTimestamp(),
            iat: $issued->getTimestamp(),
            sessionId: (string) $token->getAttribute('session_id'),
        );
    }
}
