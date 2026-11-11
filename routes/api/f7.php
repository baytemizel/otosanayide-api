<?php

use App\Http\Controllers\Api\Ads\AdEventController;
use App\Http\Controllers\Api\Ads\AdTariffController;
use App\Http\Controllers\Api\Ads\CampaignController;
use App\Http\Controllers\Api\Brand\BrandAdminController;
use App\Http\Controllers\Api\Brand\BrandFinanceController;
use App\Http\Controllers\Api\Brand\BrandServingController;
use App\Http\Controllers\Api\Payments\PaymentController;
use Illuminate\Support\Facades\Route;

// F7: reklam, kampanya, odeme, marka. Bayraklar varsayilan kapali (config/otosanayide.php flags).
Route::post('ad-events', AdEventController::class);

Route::middleware('origin:mobile,manual')->group(function (): void {
    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::patch('campaigns', [CampaignController::class, 'update']);
    Route::options('campaigns', [CampaignController::class, 'options']);
    Route::get('campaigns/finance', [CampaignController::class, 'finance']);
    Route::post('campaigns/finance', [CampaignController::class, 'finance']);
    Route::options('campaigns/finance', [CampaignController::class, 'financeOptions']);
    Route::get('campaigns/owner-metrics', [CampaignController::class, 'ownerMetrics']);
    Route::options('campaigns/owner-metrics', [CampaignController::class, 'ownerMetricsOptions']);
});
Route::get('campaigns/reservation', [CampaignController::class, 'quote']);
Route::post('campaigns/reservation', [CampaignController::class, 'reserve']);
Route::get('campaigns/metrics', [CampaignController::class, 'metrics']);

Route::prefix('admin')->group(function (): void {
    Route::get('ad-tariffs', [AdTariffController::class, 'show']);
    Route::patch('ad-tariffs', [AdTariffController::class, 'update']);
    Route::get('brand-tariffs', [BrandAdminController::class, 'tariff']);
    Route::patch('brand-tariffs', [BrandAdminController::class, 'saveTariff']);
    Route::patch('brand-capacities', [BrandAdminController::class, 'saveCapacity']);
    Route::post('brand-quote', [BrandAdminController::class, 'quote']);
    Route::get('brand-campaigns', [BrandAdminController::class, 'campaigns']);
    Route::patch('brand-campaigns', [BrandAdminController::class, 'saveCampaign']);
    Route::get('brand-reservations', [BrandAdminController::class, 'reservation']);
    Route::post('brand-reservations', [BrandAdminController::class, 'reserve']);
    Route::delete('brand-reservations', [BrandAdminController::class, 'release']);
    Route::get('brand-orders', [BrandAdminController::class, 'order']);
    Route::post('brand-orders', [BrandAdminController::class, 'prepareOrder']);

    Route::get('payment-cases', [PaymentController::class, 'cases']);
    Route::patch('payment-cases', [PaymentController::class, 'decideCase']);
    Route::get('brand-payment-review', [PaymentController::class, 'brandReview']);
    Route::patch('brand-payment-review', [PaymentController::class, 'decideBrandReview']);
    Route::get('brand-acceptance', [BrandFinanceController::class, 'acceptance']);
    Route::post('brand-acceptance', [BrandFinanceController::class, 'accept']);
    Route::get('brand-cancellations', [BrandFinanceController::class, 'cancellationShow']);
    Route::post('brand-cancellations', [BrandFinanceController::class, 'cancellationRequest']);
    Route::patch('brand-cancellations', [BrandFinanceController::class, 'cancellationDecide']);
    Route::get('brand-cancellation-queue', [BrandFinanceController::class, 'queue']);
    Route::get('brand-cancellation-history', [BrandFinanceController::class, 'history']);
    Route::get('brand-refund-review', [BrandFinanceController::class, 'refundShow']);
    Route::patch('brand-refund-review', [BrandFinanceController::class, 'refundDecide']);
});

Route::post('payments/webhook', [PaymentController::class, 'webhook']);
Route::middleware('origin:mobile,manual')->group(function (): void {
    Route::post('payments/checkout', [PaymentController::class, 'checkout']);
    Route::options('payments/checkout', [PaymentController::class, 'checkoutOptions']);
});

Route::get('brand-banners', [BrandServingController::class, 'banners']);
Route::get('brand-banners/image', [BrandServingController::class, 'image']);
Route::post('brand-events', [BrandServingController::class, 'events']);
Route::prefix('admin')->group(function (): void {
    Route::get('brand-media', [BrandServingController::class, 'media']);
    Route::post('brand-media', [BrandServingController::class, 'upload']);
    Route::get('brand-preview', [BrandServingController::class, 'preview']);
    Route::get('brand-preview/image', [BrandServingController::class, 'previewImage']);
    Route::get('brand-metrics', [BrandServingController::class, 'metrics']);
});
