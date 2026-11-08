<?php

use App\Http\Controllers\Api\Admin\BusinessChangeAdminController;
use App\Http\Controllers\Api\Admin\BusinessPhotoAdminController;
use App\Http\Controllers\Api\Admin\CatalogueAdminController;
use App\Http\Controllers\Api\Admin\ClaimAdminController;
use App\Http\Controllers\Api\Admin\ReplyAdminController;
use App\Http\Controllers\Api\Admin\ReviewAdminController;
use App\Http\Controllers\Api\Admin\ReviewCaseAdminController;
use App\Http\Controllers\Api\Admin\ReviewPhotoAdminController;
use Illuminate\Support\Facades\Route;

// Kaynakta origin kontrolu yalniz business-photos'ta var.
Route::prefix('admin')->group(function (): void {
    Route::get('business-changes', [BusinessChangeAdminController::class, 'index']);
    Route::patch('business-changes', [BusinessChangeAdminController::class, 'update']);

    Route::get('catalogue', [CatalogueAdminController::class, 'index']);
    Route::patch('catalogue', [CatalogueAdminController::class, 'update']);
    Route::get('catalogue/draft', [CatalogueAdminController::class, 'draft']);
    Route::patch('catalogue/draft', [CatalogueAdminController::class, 'saveDraft']);
    Route::get('catalogue/history', [CatalogueAdminController::class, 'history']);

    Route::post('claim-evidence', [ClaimAdminController::class, 'verifyEvidence']);
    Route::get('claim-targets', [ClaimAdminController::class, 'target']);
    Route::post('claim-targets', [ClaimAdminController::class, 'mapTarget']);
    Route::get('claims', [ClaimAdminController::class, 'index']);
    Route::patch('claims', [ClaimAdminController::class, 'update']);

    Route::get('replies', [ReplyAdminController::class, 'index']);
    Route::patch('replies', [ReplyAdminController::class, 'update']);
    Route::get('review-cases', [ReviewCaseAdminController::class, 'index']);
    Route::patch('review-cases', [ReviewCaseAdminController::class, 'update']);
    Route::get('review-photos', [ReviewPhotoAdminController::class, 'index']);
    Route::patch('review-photos', [ReviewPhotoAdminController::class, 'update']);
    Route::get('reviews', [ReviewAdminController::class, 'index']);
    Route::patch('reviews', [ReviewAdminController::class, 'update']);
    Route::post('reviews/analyze', [ReviewAdminController::class, 'analyze']);

    Route::middleware('origin:mobile,manual')->group(function (): void {
        Route::get('business-photos', [BusinessPhotoAdminController::class, 'index']);
        Route::patch('business-photos', [BusinessPhotoAdminController::class, 'update']);
        Route::options('business-photos', [BusinessPhotoAdminController::class, 'options']);
    });
});
