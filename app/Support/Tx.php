<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/** Deadlock/kilit zaman asiminda Laravel transaction'i yeniden dener; transaction icinde dis cagri yoktur. */
final class Tx
{
    public const ATTEMPTS = 5;

    /**
     * @template T
     *
     * @param  Closure():T  $work
     * @return T
     */
    public static function run(Closure $work)
    {
        return DB::transaction($work, self::ATTEMPTS);
    }
}
