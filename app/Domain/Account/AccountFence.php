<?php

namespace App\Domain\Account;

use App\Exceptions\WriteConflict;
use Illuminate\Support\Facades\DB;

/** account-write-barrier.ts: yazimla AYNI transaction'da okunur, onbellege guvenilmez. */
final class AccountFence
{
    /** @return 'deleting'|'deleted'|null */
    public static function read(string $uid): ?string
    {
        $state = DB::table('account_fences')->where('owner_uid', $uid)->sharedLock()->value('state');

        return is_string($state) ? $state : null;
    }

    public static function paused(string $uid): bool
    {
        return self::read($uid) !== null;
    }

    public static function assertWritable(string $uid): void
    {
        if (self::paused($uid)) {
            throw new WriteConflict('account_erasing');
        }
    }
}
