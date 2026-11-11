<?php

use App\Http\Controllers\Api\Me\AdminNotificationsController;
use App\Http\Controllers\Api\Me\AdminPrivacyController;
use App\Http\Controllers\Api\Me\FavoritesController;
use App\Http\Controllers\Api\Me\GarageController;
use App\Http\Controllers\Api\Me\MeController;
use App\Http\Controllers\Api\Me\NotificationsController;
use App\Http\Controllers\Api\Me\PrivacyController;
use App\Http\Controllers\Api\Me\PushController;
use App\Http\Controllers\Api\Me\RequestsController;
use Illuminate\Support\Facades\Route;

// Kaynakta /api/me ve /api/notifications origin denetlemez.
Route::get('me', [MeController::class, 'show']);
Route::post('me', [MeController::class, 'store']);
Route::patch('me', [MeController::class, 'update']);
Route::get('notifications', [NotificationsController::class, 'index']);
Route::patch('notifications', [NotificationsController::class, 'update']);
Route::post('notifications/read-all', [NotificationsController::class, 'readAll']); // web-11 (yeni uc)

Route::middleware('origin:mobile')->group(function (): void {
    Route::get('me/favorites', [FavoritesController::class, 'index']);
    Route::post('me/favorites', [FavoritesController::class, 'store']);
    Route::options('me/favorites', [FavoritesController::class, 'options']);

    Route::get('me/garage', [GarageController::class, 'show']);
    Route::put('me/garage', [GarageController::class, 'update']);
    Route::options('me/garage', [GarageController::class, 'garageOptions']);

    Route::get('me/garage/lifecycle', [GarageController::class, 'lifecycleShow']);
    Route::post('me/garage/lifecycle', [GarageController::class, 'lifecycleStore']);
    Route::options('me/garage/lifecycle', [GarageController::class, 'lifecycleOptions']);

    Route::get('me/privacy', [PrivacyController::class, 'show']);
    Route::post('me/privacy', [PrivacyController::class, 'store']);
    Route::options('me/privacy', [PrivacyController::class, 'options']);

    Route::get('requests', [RequestsController::class, 'index']);
    Route::post('requests', [RequestsController::class, 'store']);
    Route::patch('requests', [RequestsController::class, 'update']);
    Route::options('requests', [RequestsController::class, 'options']);
});

// Kaynak: migrate CORS basligi eklemez; push ret mesajini ve sorgu reddini kendi yapar.
Route::middleware('origin:mobile,nocors')->group(function (): void {
    Route::get('me/garage/migrate', [GarageController::class, 'migrateShow']);
    Route::post('me/garage/migrate', [GarageController::class, 'migrateStore']);
});
Route::middleware('origin:mobile,push')->group(function (): void {
    Route::get('me/push', [PushController::class, 'index']);
    Route::post('me/push', [PushController::class, 'store']);
    Route::options('me/push', [PushController::class, 'options']);
});

// F6'nin bu tablolara bagli iki yonetici ucu.
Route::get('admin/notifications', [AdminNotificationsController::class, 'index']);
Route::patch('admin/notifications', [AdminNotificationsController::class, 'update']);
Route::middleware('origin:web,nocors')->group(function (): void {
    Route::get('admin/privacy', [AdminPrivacyController::class, 'index']);
    Route::post('admin/privacy', [AdminPrivacyController::class, 'store']);
});
