<?php

use App\Domain\Content\BusinessPhotos;
use App\Domain\Content\Places;
use App\Domain\Content\Policy;
use App\Domain\Content\ReviewPhotos;
use Illuminate\Support\Facades\Http;

// Veritabani olmadan F5 DTO'lari: yanitlar uretim kurucularindan gelir, sekli TS ayristiricilari denetler.

it('writes F5 contract fixtures from production DTO builders', function () {
    $photoId = '22222222-2222-4222-8222-222222222222';
    $review = ['rating' => 4, 'text' => 'Sentetik yorum metni, yeterince uzun.', 'resolved' => 'yes', 'wc' => 'yes', 'quality' => 5, 'wcCleanliness' => 3, 'photoIds' => [$photoId], 'reply' => ['text' => 'Teşekkür ederiz, sentetik yanıt.'], 'userId' => 'gizli'];
    $public = Policy::publicReview('r1', $review);
    contract('public-reviews-f5', 'public-reviews', ['reviews' => [$public], 'nextCursor' => null, 'count' => 1, 'average' => 4]);
    contract('review-cases-f5', 'review-cases', ['items' => [Policy::caseView('c1', ['reviewId' => 'r1', 'businessId' => 'b1', 'kind' => 'report', 'text' => 'Sentetik rapor metni.', 'status' => 'open'])], 'next' => null]);
    contract('own-review', 'own-review', ['review' => Policy::ownReview('u', 'b1', 'r1', ['userId' => 'u', 'businessId' => 'b1', 'text' => 'Sentetik yorum metni, yeterince uzun.', 'rating' => 4, 'status' => 'approved', 'version' => 2, 'resolved' => 'yes', 'wc' => 'no'])]);
    contract('own-review-empty', 'own-review', ['review' => null]);
    contract('own-review-photos', 'own-review-photos', ['reviewId' => 'r1', 'reviewVersion' => 2, 'enabled' => false, 'canUpload' => true, 'items' => [ReviewPhotos::item($photoId, ['status' => 'pending', 'version' => 1, 'width' => 40, 'height' => 30, 'bytes' => 100, 'expiresAtMs' => 1792486800000])]]);

    $business = ['name' => 'Sentetik Motor', 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sentetik Sanayi Sitesi No 1', 'phone' => '02161234567', 'website' => '', 'hours' => '', 'description' => '', 'categories' => ['mechanical'], 'services' => ['Fren'], 'brands' => ['BMW'], 'latitude' => 41.02, 'longitude' => 29.1, 'ownerUid' => 'u', 'ownership' => 'verified', 'published' => true, 'sample' => false, 'profileVersion' => 3, 'activationVersion' => 1, 'ownerReady' => false, 'features' => ['quotes' => false, 'appointments' => false]];
    $member = ['userId' => 'u', 'businessId' => 'b1', 'role' => 'owner', 'active' => true];
    contract('business-photos', 'business-photos', ['businessId' => 'b1', 'enabled' => false, 'canUpload' => true, 'coverId' => null, 'items' => [BusinessPhotos::project($photoId, ['status' => 'pending', 'version' => 1, 'width' => 40, 'height' => 30, 'bytes' => 100, 'expiresAtMs' => 1792486800000], false, false, true)]]);
    contract('public-business-photos', 'public-business-photos', ['businessId' => 'b1', 'ids' => [$photoId], 'coverId' => $photoId]);
    contract('console-branches', 'console-branches', ['businesses' => [Policy::projectConsoleBranch('u', 'u_b1', $member, $business)], 'nextCursor' => null]);
    contract('console-reviews', 'console-reviews', ['businessId' => 'b1', 'reviews' => [$public + ['replyDraft' => ['text' => 'Teşekkür ederiz, sentetik yanıt.', 'status' => 'pending', 'version' => 1, 'reason' => '']]], 'nextCursor' => null]);
    $profile = ['name' => 'Sentetik Motor', 'city' => 'İstanbul', 'district' => 'Ümraniye', 'address' => 'Sentetik Sanayi Sitesi No 1', 'phone' => '02161234567', 'website' => '', 'hours' => '', 'description' => '', 'categories' => ['mechanical'], 'services' => ['Fren'], 'brands' => ['BMW'], 'location' => ['latitude' => 41.02, 'longitude' => 29.1]];
    contract('managed-profile', 'managed-profile', Policy::projectManagedProfile('u', 'u_b1', $member, $business, ['revisionId' => '33333333-3333-4333-8333-333333333333', 'status' => 'pending', 'profile' => $profile, 'reason' => '']));
    contract('activation', 'activation', Policy::projectActivation('u', 'b1', $business, $member, ['status' => 'paused', 'reason' => 'Sentetik durdurma gerekçesi', 'applicantId' => 'gizli']));

    Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'place_1', 'displayName' => ['text' => 'Sentetik'], 'formattedAddress' => 'Sentetik açık adres, İstanbul', 'location' => ['latitude' => 41, 'longitude' => 29], 'addressComponents' => [['shortText' => 'TR', 'types' => ['country']]]]]])]);
    contract('business-places', 'business-places', Places::google('Sentetik adres', 'tr', 'synthetic-key'));

    expect(file_exists(storage_path('contract/managed-profile.json')))->toBeTrue();
});
