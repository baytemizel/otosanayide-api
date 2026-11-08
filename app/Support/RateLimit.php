<?php

namespace App\Support;

use App\Exceptions\HttpError;
use Illuminate\Support\Facades\RateLimiter;

/** Kaynaktaki limit(uid): kullanici basina 60 sn sabit pencerede 60 islem. Atomik sayac, once artir sonra bak. */
final class RateLimit
{
    public static function consume(string $uid): void
    {
        if (RateLimiter::hit('user:'.$uid, 60) > 60) {
            throw new HttpError(429, 'Çok fazla işlem. Bir dakika sonra yeniden deneyin.');
        }
    }
}
