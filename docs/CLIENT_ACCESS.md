# İstemci erişim matrisi

Üretim: `python3 scripts/client_access.py` (route listesi ve `OriginGuard` modlarından). Sözleşme Eki v1.

- **web**: `Origin` = `PUBLIC_WEB_ORIGIN`. `WebCors` CORS yanıtı verir; kimlik bilgisi izni yalnız `/api/auth/*`.
- **portal**: `Origin` = `PORTAL_WEB_ORIGIN`. `mobile` ve `account` modlarında ve origin denetimi olmayan uçlarda web ile eşdeğerdir. `/api/admin/*` altında hiçbir modda origin izni ve CORS almaz (bilinçli karar: yönetim web `/yonetim` içindedir; portal oturumu ele geçirilse bile yönetici yüzeyine tarayıcıdan erişemez). `web` modu (yalnız `/api/admin/privacy`) portala kapalıdır.
- **native**: iOS/Android, `Origin` göndermez. Kaynaktaki `garageOriginAllowed` Origin'siz isteği geçirir; bütün korumalı uçlar yine Bearer jeton ister. Native oturum uçları `X-Client-Platform` ve `X-Client-Version` ister (`docs/AUTH_API.md`).
- **mod**: route üzerindeki `OriginGuard` modu. `yok`: kaynakta origin denetimi yok (ör. `/api/me`, `/api/claims`, çoğu `/api/admin/*`); tarayıcıdan yalnız `WebCors` izin verdiği origin okuyabilir. `exact` ve `present` modları şu an hiçbir route'ta kullanılmıyor; kullanılırsa yalnız web origin'ini kabul eder ve native/portal için ayrıca değerlendirilmelidir.
- **mobil kaynak**: `apps/mobile/src` bugün bu ucu çağırıyor (packages/core istemcileri üzerinden). Hepsi native için erişilebilir; çelişki bulunmadı.
- Yönetici uçları native'den teknik olarak çağrılabilir (kaynakta origin denetimi yok, yetki jetondaki rol ve ikinci faktördür); native uygulamalarda yönetim ekranı yoktur.

| Uç | Method | Mod | Web | Portal | Native | Mobil kaynak |
|---|---|---|---|---|---|---|
| `/api/ad-events` | POST | yok | evet | evet | evet |  |
| `/api/admin/ad-tariffs` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/ad-tariffs` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-acceptance` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-acceptance` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-campaigns` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-campaigns` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-cancellation-history` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-cancellation-queue` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-cancellations` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-cancellations` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-cancellations` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-capacities` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-media` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-media` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-metrics` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-orders` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-orders` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-payment-review` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-payment-review` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-preview` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-preview/image` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-quote` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-refund-review` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-refund-review` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-reservations` | DELETE | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-reservations` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-reservations` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-tariffs` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/brand-tariffs` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/business-changes` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/business-changes` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/business-photos` | GET | mobile,manual | evet | hayir (yonetim) | evet |  |
| `/api/admin/business-photos` | PATCH | mobile,manual | evet | hayir (yonetim) | evet |  |
| `/api/admin/business-reports` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/business-reports` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/catalogue` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/catalogue` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/catalogue/draft` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/catalogue/draft` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/catalogue/history` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/claim-evidence` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/claim-targets` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/claim-targets` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/claims` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/claims` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/flags` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/notifications` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/notifications` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/overview` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/payment-cases` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/payment-cases` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/privacy` | GET | web,nocors | evet | hayir (yonetim) | evet |  |
| `/api/admin/privacy` | POST | web,nocors | evet | hayir (yonetim) | evet |  |
| `/api/admin/replies` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/replies` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/review-cases` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/review-cases` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/review-photos` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/review-photos` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/reviews` | GET | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/reviews` | PATCH | yok | evet | hayir (yonetim) | evet |  |
| `/api/admin/reviews/analyze` | POST | yok | evet | hayir (yonetim) | evet |  |
| `/api/auth/email/change` | POST | account | evet | evet | evet |  |
| `/api/auth/email/resend` | POST | account | evet | evet | evet |  |
| `/api/auth/email/verify` | POST | account | evet | evet | evet |  |
| `/api/auth/login` | POST | account | evet | evet | evet (oturum ucunda X-Client-* zorunlu) |  |
| `/api/auth/logout` | POST | account | evet | evet | evet (oturum ucunda X-Client-* zorunlu) |  |
| `/api/auth/mfa` | GET | account | evet | evet | evet |  |
| `/api/auth/mfa/totp/confirm` | POST | account | evet | evet | evet |  |
| `/api/auth/mfa/totp/disable` | POST | account | evet | evet | evet |  |
| `/api/auth/mfa/totp/enroll` | POST | account | evet | evet | evet |  |
| `/api/auth/mfa/verify` | POST | account | evet | evet | evet (oturum ucunda X-Client-* zorunlu) |  |
| `/api/auth/password/change` | POST | account | evet | evet | evet (oturum ucunda X-Client-* zorunlu) |  |
| `/api/auth/password/forgot` | POST | account | evet | evet | evet |  |
| `/api/auth/password/reset` | POST | account | evet | evet | evet |  |
| `/api/auth/refresh` | POST | account | evet | evet | evet (oturum ucunda X-Client-* zorunlu) |  |
| `/api/auth/register` | POST | account | evet | evet | evet |  |
| `/api/brand-banners` | GET | yok | evet | evet | evet |  |
| `/api/brand-banners/image` | GET | yok | evet | evet | evet |  |
| `/api/brand-events` | POST | yok | evet | evet | evet |  |
| `/api/businesses/activation` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/activation` | PATCH | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/capacity` | GET | mobile | evet | evet | evet |  |
| `/api/businesses/capacity` | PATCH | mobile | evet | evet | evet |  |
| `/api/businesses/capacity/closures` | DELETE | mobile | evet | evet | evet |  |
| `/api/businesses/capacity/closures` | POST | mobile | evet | evet | evet |  |
| `/api/businesses/console` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/insights` | GET | mobile | evet | evet | evet |  |
| `/api/businesses/manage` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/manage` | PATCH | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/photos` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/photos` | PATCH | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/photos` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/photos/media` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/photos/public` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/places` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/replies` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/replies` | PATCH | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/replies` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/businesses/team` | GET | mobile | evet | evet | evet |  |
| `/api/businesses/team` | PATCH | mobile | evet | evet | evet |  |
| `/api/businesses/team/invitations` | POST | mobile | evet | evet | evet |  |
| `/api/businesses/team/invitations/accept` | POST | mobile | evet | evet | evet |  |
| `/api/businesses/{id}` | GET | yok | evet | evet | evet |  |
| `/api/businesses/{id}/availability` | GET | mobile | evet | evet | evet |  |
| `/api/businesses/{id}/reports` | POST | mobile | evet | evet | evet |  |
| `/api/campaigns` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/campaigns` | PATCH | mobile,manual | evet | evet | evet | evet |
| `/api/campaigns/finance` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/campaigns/finance` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/campaigns/metrics` | GET | yok | evet | evet | evet |  |
| `/api/campaigns/owner-metrics` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/campaigns/reservation` | GET | yok | evet | evet | evet |  |
| `/api/campaigns/reservation` | POST | yok | evet | evet | evet |  |
| `/api/claims` | GET | yok | evet | evet | evet |  |
| `/api/claims` | PATCH | yok | evet | evet | evet |  |
| `/api/claims` | POST | yok | evet | evet | evet |  |
| `/api/claims/evidence` | GET | yok | evet | evet | evet |  |
| `/api/claims/evidence` | PATCH | yok | evet | evet | evet |  |
| `/api/claims/evidence` | POST | yok | evet | evet | evet |  |
| `/api/coverage/waitlist` | DELETE | mobile | evet | evet | evet |  |
| `/api/coverage/waitlist` | GET | mobile | evet | evet | evet |  |
| `/api/coverage/waitlist` | POST | mobile | evet | evet | evet |  |
| `/api/health` | GET | yok | evet | evet | evet |  |
| `/api/me` | GET | yok | evet | evet | evet |  |
| `/api/me` | PATCH | yok | evet | evet | evet |  |
| `/api/me` | POST | yok | evet | evet | evet |  |
| `/api/me/favorites` | GET | mobile | evet | evet | evet | evet |
| `/api/me/favorites` | POST | mobile | evet | evet | evet | evet |
| `/api/me/garage` | GET | mobile | evet | evet | evet | evet |
| `/api/me/garage` | PUT | mobile | evet | evet | evet | evet |
| `/api/me/garage/lifecycle` | GET | mobile | evet | evet | evet | evet |
| `/api/me/garage/lifecycle` | POST | mobile | evet | evet | evet | evet |
| `/api/me/garage/migrate` | GET | mobile,nocors | evet | evet | evet |  |
| `/api/me/garage/migrate` | POST | mobile,nocors | evet | evet | evet |  |
| `/api/me/lists` | DELETE | mobile | evet | evet | evet |  |
| `/api/me/lists` | GET | mobile | evet | evet | evet |  |
| `/api/me/lists` | PATCH | mobile | evet | evet | evet |  |
| `/api/me/lists` | POST | mobile | evet | evet | evet |  |
| `/api/me/preferences` | GET | mobile | evet | evet | evet |  |
| `/api/me/preferences` | PATCH | mobile | evet | evet | evet |  |
| `/api/me/privacy` | GET | mobile | evet | evet | evet | evet |
| `/api/me/privacy` | POST | mobile | evet | evet | evet | evet |
| `/api/me/privacy/exports` | GET | mobile | evet | evet | evet |  |
| `/api/me/privacy/exports` | PATCH | mobile | evet | evet | evet |  |
| `/api/me/privacy/exports` | POST | mobile | evet | evet | evet |  |
| `/api/me/push` | GET | mobile,push | evet | evet | evet | evet |
| `/api/me/push` | POST | mobile,push | evet | evet | evet | evet |
| `/api/notifications` | GET | yok | evet | evet | evet |  |
| `/api/notifications` | PATCH | yok | evet | evet | evet |  |
| `/api/notifications/read-all` | POST | yok | evet | evet | evet |  |
| `/api/payments/checkout` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/payments/webhook` | POST | yok | evet | evet | evet |  |
| `/api/requests` | GET | mobile | evet | evet | evet | evet |
| `/api/requests` | PATCH | mobile | evet | evet | evet | evet |
| `/api/requests` | POST | mobile | evet | evet | evet | evet |
| `/api/requests/messages` | GET | mobile | evet | evet | evet |  |
| `/api/requests/messages` | POST | mobile | evet | evet | evet |  |
| `/api/review-cases` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/review-cases` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/review-photos` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/review-photos` | PATCH | mobile,manual | evet | evet | evet | evet |
| `/api/review-photos` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/review-photos/media` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/reviews` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/reviews` | POST | mobile,manual | evet | evet | evet | evet |
| `/api/reviews/mine` | GET | mobile,manual | evet | evet | evet | evet |
| `/api/reviews/mine` | PATCH | mobile,manual | evet | evet | evet | evet |
| `/api/reviews/mine/all` | GET | mobile | evet | evet | evet |  |
| `/api/search` | GET | mobile,manual | evet | evet | evet | evet |
