<?php

use App\Http\Controllers\Api\Admin\OperationsAdminController;
use App\Http\Controllers\Api\Content\BusinessToolsController as Tools;
use App\Http\Controllers\Api\Me\AccountExtrasController as Extras;
use App\Http\Controllers\Api\Me\RequestThreadController;
use Illuminate\Support\Facades\Route;

// API-A 2. tur (docs/endpoint-requests). `businesses/{id}` dinamik yolundan once yuklenir.
// mobile modu: web, portal, MOBILE_WEB_ORIGIN ve Origin'siz native istemci.
Route::middleware('origin:mobile')->group(function (): void {
    Route::get('me/preferences', [Extras::class, 'preferences']);              // web-09
    Route::patch('me/preferences', [Extras::class, 'updatePreferences']);
    Route::get('me/lists', [Extras::class, 'lists']);                          // web-13
    Route::post('me/lists', [Extras::class, 'createList']);
    Route::patch('me/lists', [Extras::class, 'updateList']);
    Route::delete('me/lists', [Extras::class, 'deleteList']);
    Route::get('me/privacy/exports', [Extras::class, 'exports']);              // web-10
    Route::post('me/privacy/exports', [Extras::class, 'createExport']);
    Route::patch('me/privacy/exports', [Extras::class, 'cancelExport']);
    Route::get('coverage/waitlist', [Extras::class, 'waitlist']);              // web-12
    Route::post('coverage/waitlist', [Extras::class, 'joinWaitlist']);
    Route::delete('coverage/waitlist', [Extras::class, 'leaveWaitlist']);

    Route::get('reviews/mine/all', [\App\Http\Controllers\Api\Content\OwnReviewsController::class, 'index']); // web-17
    Route::get('requests/messages', [RequestThreadController::class, 'index']);  // web-15/2
    Route::post('requests/messages', [RequestThreadController::class, 'store']);

    Route::get('businesses/insights', [Tools::class, 'insights']);             // portal-02
    Route::get('businesses/team', [Tools::class, 'team']);                     // portal-01
    Route::patch('businesses/team', [Tools::class, 'changeTeam']);
    Route::post('businesses/team/invitations', [Tools::class, 'invite']);
    Route::post('businesses/team/invitations/accept', [Tools::class, 'acceptInvitation']);
    Route::get('businesses/capacity', [Tools::class, 'capacity']);             // portal-03
    Route::patch('businesses/capacity', [Tools::class, 'updateCapacity']);
    Route::post('businesses/capacity/closures', [Tools::class, 'addClosure']);
    Route::delete('businesses/capacity/closures', [Tools::class, 'removeClosure']);

    Route::post('businesses/{id}/reports', [Tools::class, 'report'])->where('id', '[a-zA-Z0-9_-]{1,120}');           // web-04
    Route::get('businesses/{id}/availability', [Tools::class, 'availability'])->where('id', '[a-zA-Z0-9_-]{1,120}'); // web-15/3
});

// Yonetici: kaynaktaki admin uclari gibi origin denetimi yok; portal WebCors'tan CORS almaz.
Route::prefix('admin')->group(function (): void {
    Route::get('overview', [OperationsAdminController::class, 'overview']);   // web-16/1
    Route::get('flags', [OperationsAdminController::class, 'flags']);         // web-16/4
    Route::get('business-reports', [OperationsAdminController::class, 'reports']);  // web-04
    Route::patch('business-reports', [OperationsAdminController::class, 'decideReport']);
});
