# Route paritesi

Kaynak: apps/web/src/app/api. 149 method. bekliyor: 49, tamam: 100.

`tamam`: kod yazildi ve test edildi; canli kabul degildir. MySQL testleri 178c0f5 commitinde bir kez gecti, sonra depodan kaldirildi (K178). `kapali bayrak`: kaynaktaki kapali yanit. `bekliyor`: yazilmadi.

| Route | Method | Durum | Controller | Test |
|---|---|---|---|---|
| `/api/ad-events` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/ad-tariffs` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/ad-tariffs` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-acceptance` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-acceptance` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-campaigns` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-campaigns` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-cancellation-history` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-cancellation-queue` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-cancellations` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-cancellations` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-cancellations` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-capacities` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-media` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-media` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-metrics` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-orders` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-orders` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-payment-review` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-payment-review` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-preview/image` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-preview` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-quote` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-refund-review` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-refund-review` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-reservations` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-reservations` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-reservations` | DELETE | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-tariffs` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/brand-tariffs` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/business-changes` | GET | tamam | Admin/BusinessChangeAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/business-changes` | PATCH | tamam | Admin/BusinessChangeAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/business-photos` | GET | tamam | Admin/BusinessPhotoAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/business-photos` | PATCH | tamam | Admin/BusinessPhotoAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/business-photos` | OPTIONS | tamam | Admin/BusinessPhotoAdminController::options | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/catalogue/draft` | GET | tamam | Admin/CatalogueAdminController::draft | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/catalogue/draft` | PATCH | tamam | Admin/CatalogueAdminController::saveDraft | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/catalogue/history` | GET | tamam | Admin/CatalogueAdminController::history | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/catalogue` | GET | tamam | Admin/CatalogueAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/catalogue` | PATCH | tamam | Admin/CatalogueAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/claim-evidence` | POST | tamam | Admin/ClaimAdminController::verifyEvidence | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/claim-targets` | GET | tamam | Admin/ClaimAdminController::target | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/claim-targets` | POST | tamam | Admin/ClaimAdminController::mapTarget | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/claims` | GET | tamam | Admin/ClaimAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/claims` | PATCH | tamam | Admin/ClaimAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/notifications` | GET | tamam | Me/AdminNotificationsController::index | tests/Feature/F6AdminOfflineTest.php |
| `/api/admin/notifications` | PATCH | tamam | Me/AdminNotificationsController::update | tests/Feature/F6AdminOfflineTest.php |
| `/api/admin/payment-cases` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/payment-cases` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/admin/privacy` | GET | tamam | Me/AdminPrivacyController::index | tests/Feature/F6AdminOfflineTest.php |
| `/api/admin/privacy` | POST | tamam | Me/AdminPrivacyController::store | tests/Feature/F6AdminOfflineTest.php |
| `/api/admin/replies` | GET | tamam | Admin/ReplyAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/replies` | PATCH | tamam | Admin/ReplyAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/review-cases` | GET | tamam | Admin/ReviewCaseAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/review-cases` | PATCH | tamam | Admin/ReviewCaseAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/review-photos` | GET | tamam | Admin/ReviewPhotoAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/review-photos` | PATCH | tamam | Admin/ReviewPhotoAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/reviews/analyze` | POST | tamam | Admin/ReviewAdminController::analyze | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/reviews` | GET | tamam | Admin/ReviewAdminController::index | tests/Feature/F6ContentOfflineTest.php |
| `/api/admin/reviews` | PATCH | tamam | Admin/ReviewAdminController::update | tests/Feature/F6ContentOfflineTest.php |
| `/api/brand-banners/image` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/brand-banners` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/brand-events` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/businesses/activation` | GET | tamam | Content/ActivationController::index | tests/Feature/F5OfflineTest.php |
| `/api/businesses/activation` | PATCH | tamam | Content/ActivationController::update | tests/Feature/F5OfflineTest.php |
| `/api/businesses/activation` | OPTIONS | tamam | Content/ActivationController::options | tests/Feature/F5OfflineTest.php |
| `/api/businesses/console` | GET | tamam | Content/ConsoleController::index | tests/Feature/F5OfflineTest.php |
| `/api/businesses/console` | OPTIONS | tamam | Content/ConsoleController::options | tests/Feature/F5OfflineTest.php |
| `/api/businesses/manage` | GET | tamam | Content/ManageController::index | tests/Feature/F5OfflineTest.php |
| `/api/businesses/manage` | PATCH | tamam | Content/ManageController::update | tests/Feature/F5OfflineTest.php |
| `/api/businesses/manage` | OPTIONS | tamam | Content/ManageController::options | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos/media` | GET | tamam | Content/BusinessPhotoController::media | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos/media` | OPTIONS | tamam | Content/BusinessPhotoController::mediaOptions | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos/public` | GET | tamam | Content/BusinessPhotoController::publicList | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos/public` | OPTIONS | tamam | Content/BusinessPhotoController::publicOptions | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos` | GET | tamam | Content/BusinessPhotoController::index | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos` | POST | tamam | Content/BusinessPhotoController::store | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos` | PATCH | tamam | Content/BusinessPhotoController::update | tests/Feature/F5OfflineTest.php |
| `/api/businesses/photos` | OPTIONS | tamam | Content/BusinessPhotoController::options | tests/Feature/F5OfflineTest.php |
| `/api/businesses/places` | POST | tamam | Content/PlacesController::store | tests/Feature/F5OfflineTest.php |
| `/api/businesses/places` | OPTIONS | tamam | Content/PlacesController::options | tests/Feature/F5OfflineTest.php |
| `/api/businesses/replies` | GET | tamam | Content/ReplyController::index | tests/Feature/F5OfflineTest.php |
| `/api/businesses/replies` | POST | tamam | Content/ReplyController::store | tests/Feature/F5OfflineTest.php |
| `/api/businesses/replies` | PATCH | tamam | Content/ReplyController::update | tests/Feature/F5OfflineTest.php |
| `/api/businesses/replies` | OPTIONS | tamam | Content/ReplyController::options | tests/Feature/F5OfflineTest.php |
| `/api/campaigns/finance` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns/finance` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns/finance` | OPTIONS | bekliyor | Closed/ClosedFeatureController::options | tests/Feature/F7ClosedTest.php |
| `/api/campaigns/metrics` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns/owner-metrics` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns/owner-metrics` | OPTIONS | bekliyor | Closed/ClosedFeatureController::options | tests/Feature/F7ClosedTest.php |
| `/api/campaigns/reservation` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns/reservation` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns` | GET | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns` | PATCH | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/campaigns` | OPTIONS | bekliyor | Closed/ClosedFeatureController::options | tests/Feature/F7ClosedTest.php |
| `/api/claims/evidence` | GET | tamam | Content/ClaimEvidenceController::index | tests/Feature/F5OfflineTest.php |
| `/api/claims/evidence` | POST | tamam | Content/ClaimEvidenceController::store | tests/Feature/F5OfflineTest.php |
| `/api/claims/evidence` | PATCH | tamam | Content/ClaimEvidenceController::update | tests/Feature/F5OfflineTest.php |
| `/api/claims` | GET | tamam | Content/ClaimController::index | tests/Feature/F5OfflineTest.php |
| `/api/claims` | POST | tamam | Content/ClaimController::store | tests/Feature/F5OfflineTest.php |
| `/api/claims` | PATCH | tamam | Content/ClaimController::update | tests/Feature/F5OfflineTest.php |
| `/api/health` | GET | tamam | HealthController | tests/Feature/F1FoundationTest.php |
| `/api/me/favorites` | GET | tamam | Me/FavoritesController::index | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/favorites` | POST | tamam | Me/FavoritesController::store | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/favorites` | OPTIONS | tamam | Me/FavoritesController::options | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage/lifecycle` | GET | tamam | Me/GarageController::lifecycleShow | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage/lifecycle` | POST | tamam | Me/GarageController::lifecycleStore | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage/lifecycle` | OPTIONS | tamam | Me/GarageController::lifecycleOptions | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage/migrate` | GET | tamam | Me/GarageController::migrateShow | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage/migrate` | POST | tamam | Me/GarageController::migrateStore | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage` | GET | tamam | Me/GarageController::show | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage` | PUT | tamam | Me/GarageController::update | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/garage` | OPTIONS | tamam | Me/GarageController::garageOptions | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/privacy` | GET | tamam | Me/PrivacyController::show | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/privacy` | POST | tamam | Me/PrivacyController::store | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/privacy` | OPTIONS | tamam | Me/PrivacyController::options | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/push` | GET | tamam | Me/PushController::index | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/push` | POST | tamam | Me/PushController::store | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me/push` | OPTIONS | tamam | Me/PushController::options | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me` | GET | tamam | Me/MeController::show | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me` | POST | tamam | Me/MeController::store | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/me` | PATCH | tamam | Me/MeController::update | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/notifications` | GET | tamam | Me/NotificationsController::index | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/notifications` | PATCH | tamam | Me/NotificationsController::update | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/payments/checkout` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/payments/checkout` | OPTIONS | bekliyor | Closed/ClosedFeatureController::options | tests/Feature/F7ClosedTest.php |
| `/api/payments/webhook` | POST | bekliyor | Closed/ClosedFeatureController | tests/Feature/F7ClosedTest.php |
| `/api/requests` | GET | tamam | Me/RequestsController::index | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/requests` | POST | tamam | Me/RequestsController::store | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/requests` | PATCH | tamam | Me/RequestsController::update | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/requests` | OPTIONS | tamam | Me/RequestsController::options | tests/Unit/F4RulesTest.php, tests/Feature/F4OfflineTest.php |
| `/api/review-cases` | GET | tamam | Content/ReviewCaseController::index | tests/Feature/F5OfflineTest.php |
| `/api/review-cases` | POST | tamam | Content/ReviewCaseController::store | tests/Feature/F5OfflineTest.php |
| `/api/review-cases` | OPTIONS | tamam | Content/ReviewCaseController::options | tests/Feature/F5OfflineTest.php |
| `/api/review-photos/media` | GET | tamam | Content/ReviewPhotoController::media | tests/Feature/F5OfflineTest.php |
| `/api/review-photos/media` | OPTIONS | tamam | Content/ReviewPhotoController::mediaOptions | tests/Feature/F5OfflineTest.php |
| `/api/review-photos` | GET | tamam | Content/ReviewPhotoController::index | tests/Feature/F5OfflineTest.php |
| `/api/review-photos` | POST | tamam | Content/ReviewPhotoController::store | tests/Feature/F5OfflineTest.php |
| `/api/review-photos` | PATCH | tamam | Content/ReviewPhotoController::update | tests/Feature/F5OfflineTest.php |
| `/api/review-photos` | OPTIONS | tamam | Content/ReviewPhotoController::options | tests/Feature/F5OfflineTest.php |
| `/api/reviews/mine` | GET | tamam | Content/ReviewController::mine | tests/Feature/F5OfflineTest.php |
| `/api/reviews/mine` | PATCH | tamam | Content/ReviewController::changeMine | tests/Feature/F5OfflineTest.php |
| `/api/reviews/mine` | OPTIONS | tamam | Content/ReviewController::mineOptions | tests/Feature/F5OfflineTest.php |
| `/api/reviews` | GET | tamam | Content/ReviewController::index | tests/Feature/F5OfflineTest.php |
| `/api/reviews` | POST | tamam | Content/ReviewController::store | tests/Feature/F5OfflineTest.php |
| `/api/reviews` | OPTIONS | tamam | Content/ReviewController::options | tests/Feature/F5OfflineTest.php |
| `/api/search` | GET | tamam | Catalogue/SearchController::index | tests/Feature/F3SearchOfflineTest.php |
| `/api/search` | OPTIONS | tamam | Catalogue/SearchController::options | tests/Feature/F3SearchOfflineTest.php |
