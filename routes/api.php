<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class);

Route::prefix('auth')->middleware('origin:web')->controller(AuthController::class)->group(function (): void {
    Route::post('register', 'register');
    Route::post('login', 'login');
    Route::post('mfa/verify', 'mfaVerify');
    Route::post('refresh', 'refresh');
    Route::post('logout', 'logout');
    Route::post('email/verify', 'verifyEmail');
    Route::post('email/resend', 'resendVerification');
    Route::post('password/forgot', 'forgot');
    Route::post('password/reset', 'reset');
    Route::post('mfa/totp/enroll', 'totpEnroll');
    Route::post('mfa/totp/confirm', 'totpConfirm');
    Route::post('mfa/totp/disable', 'totpDisable');
});
