<?php

namespace App\Support;

use App\Exceptions\HttpError;
use Illuminate\Support\Facades\RateLimiter;

/** Kimlik uclari icin anahtar basina sabit pencere (database surucusu, atomik sayac). */
final class Throttle
{
    public static function hit(string $scope, string $value, int $max, int $decaySeconds): void
    {
        if (RateLimiter::hit('auth:'.$scope.':'.hash('sha256', $value), $decaySeconds) > $max) {
            throw new HttpError(429, 'Çok fazla işlem. Bir dakika sonra yeniden deneyin.');
        }
    }
}
