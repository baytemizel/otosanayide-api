<?php

namespace App\Domain\Account;

use App\Exceptions\HttpError;
use Illuminate\Support\Facades\DB;
use stdClass;

/** businessMemberships okumalari. Transaction icinde $lock=true kullanilir. */
final class Membership
{
    public static function find(string $uid, string $businessId, bool $lock = false): ?stdClass
    {
        $q = DB::table('business_memberships')->where('user_id', $uid)->where('business_id', $businessId);

        return ($lock ? $q->sharedLock() : $q)->first();
    }

    /** requestMember(): aktif owner/staff. */
    public static function isMember(string $uid, ?stdClass $m): bool
    {
        return $m !== null && $m->user_id === $uid && (bool) $m->active && in_array($m->role, ['owner', 'staff'], true);
    }

    /** server.ts membership(): degilse 403. */
    public static function require(string $uid, string $businessId): stdClass
    {
        $m = self::find($uid, $businessId);
        if (! self::isMember($uid, $m)) {
            throw new HttpError(403, 'Bu işletme için yetkiniz yok.');
        }

        return $m;
    }
}
