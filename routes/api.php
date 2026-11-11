<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Catalogue\BusinessController;
use App\Http\Controllers\Api\Catalogue\SearchController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);

// Web + portal cerezle, native (Origin yok) govdede yenileme jetonuyla: docs/AUTH_API.md.
Route::prefix('auth')->middleware('origin:account')->controller(AuthController::class)->group(function (): void {
    Route::post('register', 'register');
    Route::post('login', 'login');
    Route::post('mfa/verify', 'mfaVerify');
    Route::post('refresh', 'refresh');
    Route::post('logout', 'logout');
    Route::post('email/verify', 'verifyEmail');
    Route::post('email/resend', 'resendVerification');
    Route::post('password/forgot', 'forgot');
    Route::post('password/reset', 'reset');
    Route::post('password/change', 'changePassword');
    Route::post('email/change', 'changeEmail');
    Route::get('mfa', 'mfaStatus');
    Route::post('mfa/totp/enroll', 'totpEnroll');
    Route::post('mfa/totp/confirm', 'totpConfirm');
    Route::post('mfa/totp/disable', 'totpDisable');
});

Route::middleware('origin:mobile,manual')->group(function (): void {
    Route::get('search', [SearchController::class, 'index']);
    Route::options('search', [SearchController::class, 'options']);
});
foreach (['f4', 'f5', 'f6', 'f7', 'r2'] as $phase) {
    if (is_file(__DIR__.'/api/'.$phase.'.php')) {
        require __DIR__.'/api/'.$phase.'.php';
    }
}

// Daima en sonda: F5'teki businesses/activation, manage, console gibi sabit yollar bundan once kaydedilmeli.
Route::get('businesses/{id}', [BusinessController::class, 'show'])->where('id', '[a-zA-Z0-9_-]{1,120}');
