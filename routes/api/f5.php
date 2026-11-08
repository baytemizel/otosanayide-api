<?php

use App\Http\Controllers\Api\Content\ActivationController;
use App\Http\Controllers\Api\Content\BusinessPhotoController;
use App\Http\Controllers\Api\Content\ClaimController;
use App\Http\Controllers\Api\Content\ClaimEvidenceController;
use App\Http\Controllers\Api\Content\ConsoleController;
use App\Http\Controllers\Api\Content\ManageController;
use App\Http\Controllers\Api\Content\PlacesController;
use App\Http\Controllers\Api\Content\ReplyController;
use App\Http\Controllers\Api\Content\ReviewCaseController;
use App\Http\Controllers\Api\Content\ReviewController;
use App\Http\Controllers\Api\Content\ReviewPhotoController;
use Illuminate\Support\Facades\Route;

// Kaynakta origin kontrolu olmayan yollar.
Route::get('claims', [ClaimController::class, 'index']);
Route::post('claims', [ClaimController::class, 'store']);
Route::patch('claims', [ClaimController::class, 'update']);
Route::options('claims', [ClaimController::class, 'options']);
Route::get('claims/evidence', [ClaimEvidenceController::class, 'index']);
Route::post('claims/evidence', [ClaimEvidenceController::class, 'store']);
Route::patch('claims/evidence', [ClaimEvidenceController::class, 'update']);
Route::options('claims/evidence', [ClaimEvidenceController::class, 'options']);

// garageOriginAllowed + cors(): handler kendi ret ve CORS yanitini kurar.
Route::middleware('origin:mobile,manual')->group(function (): void {
    Route::get('reviews', [ReviewController::class, 'index']);
    Route::post('reviews', [ReviewController::class, 'store']);
    Route::options('reviews', [ReviewController::class, 'options']);
    Route::get('reviews/mine', [ReviewController::class, 'mine']);
    Route::patch('reviews/mine', [ReviewController::class, 'changeMine']);
    Route::options('reviews/mine', [ReviewController::class, 'mineOptions']);

    Route::get('review-cases', [ReviewCaseController::class, 'index']);
    Route::post('review-cases', [ReviewCaseController::class, 'store']);
    Route::options('review-cases', [ReviewCaseController::class, 'options']);

    Route::get('review-photos', [ReviewPhotoController::class, 'index']);
    Route::post('review-photos', [ReviewPhotoController::class, 'store']);
    Route::patch('review-photos', [ReviewPhotoController::class, 'update']);
    Route::options('review-photos', [ReviewPhotoController::class, 'options']);
    Route::get('review-photos/media', [ReviewPhotoController::class, 'media']);
    Route::options('review-photos/media', [ReviewPhotoController::class, 'mediaOptions']);

    Route::get('businesses/manage', [ManageController::class, 'index']);
    Route::patch('businesses/manage', [ManageController::class, 'update']);
    Route::options('businesses/manage', [ManageController::class, 'options']);

    Route::get('businesses/photos', [BusinessPhotoController::class, 'index']);
    Route::post('businesses/photos', [BusinessPhotoController::class, 'store']);
    Route::patch('businesses/photos', [BusinessPhotoController::class, 'update']);
    Route::options('businesses/photos', [BusinessPhotoController::class, 'options']);
    Route::get('businesses/photos/media', [BusinessPhotoController::class, 'media']);
    Route::options('businesses/photos/media', [BusinessPhotoController::class, 'mediaOptions']);
    Route::get('businesses/photos/public', [BusinessPhotoController::class, 'publicList']);
    Route::options('businesses/photos/public', [BusinessPhotoController::class, 'publicOptions']);

    Route::get('businesses/replies', [ReplyController::class, 'index']);
    Route::post('businesses/replies', [ReplyController::class, 'store']);
    Route::patch('businesses/replies', [ReplyController::class, 'update']);
    Route::options('businesses/replies', [ReplyController::class, 'options']);

    Route::get('businesses/activation', [ActivationController::class, 'index']);
    Route::patch('businesses/activation', [ActivationController::class, 'update']);
    Route::options('businesses/activation', [ActivationController::class, 'options']);

    Route::get('businesses/console', [ConsoleController::class, 'index']);
    Route::options('businesses/console', [ConsoleController::class, 'options']);

    Route::post('businesses/places', [PlacesController::class, 'store']);
    Route::options('businesses/places', [PlacesController::class, 'options']);
});
