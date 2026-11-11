# Şema haritası

Firestore koleksiyonu ile MySQL tablosu eşleşmesi. Sıra fazlarla genişler; yalnız yazılmış tablolar listelenir.

| Tablo | Kaynak | Not |
|---|---|---|
| `auth_accounts` | Firebase Auth kullanıcısı | Yetkiler üç boolean kolon (0002'deki `claims` JSON yerine) |
| `auth_sessions` | Firebase refresh | Yenileme yalnız SHA-256 özeti; `family_id` tekrar kullanımda iptal; `auth_time` korunur |
| `personal_access_tokens` | Firebase ID token | Sanctum; `tokenable_id` metin, `session_id` ile oturuma bağlı |
| `auth_email_tokens` | Firebase e-posta bağlantıları | Tek kullanımlık, yalnız özet |
| `auth_mfa_factors`, `auth_mfa_challenges` | Firebase MFA | TOTP sırrı `Crypt` ile şifreli |
| `auth_events` | yeni | Ham IP yok, HMAC |
| `businesses` | `businesses` | `latitude/longitude` DOUBLE: `profileProjection` tam sayı eşitliği karşılaştırır |
| `business_values` | `businesses.categories/services/brands` | Sıra `position` ile korunur |
| `business_public_photos` | `businesses.publicBusinessPhotoIds` | |
| `business_history` | `businesses/*/history` | `details` yazara göre değişen denetim yükü, JSON |
| `catalogue_sources` | `catalogueSources` | `raw_source` ve `extra` opak JSON |
| `catalogue_source_values` | `catalogueSources.source.*Candidates, flags` | |
| `business_search`, `business_search_facets` | Meili indeksi | Türetilmiş; işletme yazımıyla aynı transaction'da `SearchProjection::sync` |
| `account_fences` | `accountFences` | Yazarlar aynı transaction'da okur |
| `business_memberships` | `businessMemberships/{uid}_{businessId}` | Bileşik birincil anahtar |
| `user_vehicles`, `user_maintenance`, `user_reminders` | `users/*/vehicles`, `/maintenance`, `/reminders` | Kanal dizisi iki boolean + `channels_set`; `attachment_ids` yalnız boş/yok ayrımı |
| `user_favorites` | `users/*/favorites` | FK yok: silinen işletme favoride `null` görünür |
| `garage_backups`, `garage_recycle` | `users/*/privateGarageBackups`, `/garageRecycle` | `garage`/`backup` opak anlık görüntü: bütün olarak okunur ve şemadan geçer |
| `garage_state` | `users/*/garageState` | |
| `privacy_requests`, `privacy_state`, `privacy_request_history` | `users/*/privacyRequests`, `/privacyState`, `.../history` | |
| `push_devices` | `users/*/pushTokens`, `pushTokenClaims` | Claim belgesi `token_hash` UNIQUE oldu |
| `requests`, `request_history` | `requests`, `requests/*/history` | `original` sütunu yok: alanlar değişmez, doğrudan karşılaştırılır |
| `appointment_slots` | `appointmentSlots/{businessId}_{ms}` | Birincil anahtar çifte randevuyu engeller |
| `notification_jobs`, `notification_job_history` | `notificationJobs`, `.../history` | |
| `notifications`, `notification_reads` | `notifications`, `notificationReads` | Okundu anahtarı (bildirim, kullanıcı) |
| `cache`, `cache_locks`, `jobs`, `failed_jobs` | yeni / `rateLimits` | Hız sınırı database cache'te; `cache.key` büyük/küçük harf duyarlı |
| `push_devices.provider`, `push_devices.apns_environment` | yeni (Sözleşme Eki v1) | `fcm` (varsayılan, mevcut satırlar) veya `apns`; APNs ortamı yalnız `apns` için. Migration `2026_11_11_010001` |
| `notifications.reminder_id`, `vehicle_id`, `email_pending`, `push_pending`, `*_queue_closed_at_ms`, `*_queue_outcome` | `notifications` (reminderId, vehicleId, emailPending, pushPending, *QueueClosedAtMs, *QueueOutcome) | `reminder_inbox` yazar; dış kanal kuyruğu `(email_pending,id)`/`(push_pending,id)` indeksiyle taranır |
| `notification_deliveries` | `notifications.emailDelivery`, `notifications.pushDelivery[deviceId]` | prepared → dispatched → accepted/invalid_token/uncertain; e-postada `device_id` boş metin |
| `scheduled_runs` | `scheduledRuns/{job}` | İş başına tek kayıt; `schedulerControl` yerine bayraklar + `binding_digest`. Kurtarma `ops:scheduled-recover` |
| `ad_campaigns`, `ad_campaign_history`, `ad_tariffs`, `ad_tariff_history`, `ad_inventory_days`, `ad_campaign_reservations`, `ad_reservations`, `ad_reservation_history`, `ad_orders`, `ad_payment_receipts`, `ad_payment_cases`, `ad_payment_case_history`, `ad_metric_events`, `ad_campaign_metrics` | `campaigns`, `adTariffs`, `adInventory`, `campaignReservations`, `adReservations`, `adOrders`, `adPaymentReceipts`, `adPaymentCases`, `adMetric*` | F7 (API-B), migration `2026_11_11_070001`; ayrıntı `docs/schema/f7.md`. Sponsorlu arama sunumu (`App\Domain\Ads\SponsoredServing`) bu tabloları salt okur |
| `brand_*` (30 tablo) | `brandCampaigns`, `brandMedia*`, `brandTariffs`, `brandCapacities`, `brandInventory`, `brandReservations`, `brandOrders`, `brandPayment*`, `brandAcceptances`, `brandCancellations`, `brandRefund*`, `brandMetric*` | F7 (API-B), migration `2026_11_11_070002`; ayrıntı `docs/schema/f7.md` |
| `payment_checkout_sessions`, `payment_gateway_inbox` | `paymentCheckoutSessions`, `paymentGatewayInbox` | F7 (API-B), migration `2026_11_11_070003`; webhook gelen kutusu (provider+merchant+eventId) |
| `auth_email_changes` | yeni (web-03) | E-posta değişim bağlantısı; yalnız özet; `auth_email_tokens.purpose` enum'u değiştirilmedi. Migration `2026_11_11_020001` (aşağıdakilerin hepsi) |
| `businesses.opening_hours`, `business_changes.opening_hours_action`, `business_changes.opening_hours` | yeni (web-07, portal-04) | JSON, Europe/Istanbul; bekleyen revizyon profil onayıyla uygulanır |
| `business_search.review_count`, `review_sum`, `review_score` | `businesses.reviewCount/reviewRatingSum` türevi | web-05/08: `public_reviews`'tan, yorum yayımlama transaction'ında; Bayes puanı (C=5, m=3,5 öneri). `reviews:backfill-summary` |
| `business_reports` | yeni (web-04) | Yanlış bilgi bildirimi; profili değiştirmez |
| `requests.offer_lines`, `offer_currency`, `offer_excluded`, `offer_may_change`, `preferred_starts_at_ms` | yeni (portal-05, portal-03) | Kalemler kuruş JSON; yalnız `?v=2` okumasında döner |
| `request_messages` | yeni (web-15/2) | Talep yazışması; `(request_id, operation_id)` UNIQUE |
| `business_appointment_settings`, `business_closures`, `appointment_bookings` | yeni (portal-03) | Kapasite defteri; ayar yoksa eski `appointment_slots` tekil kuralı |
| `business_invitations`, `business_team_audit` | yeni (portal-01) | Davet yalnız `staff`; token özeti; denetim kaydı |
| `user_preferences`, `user_consent_events` | yeni (web-09) | Ürün duyurusu varsayılan kapalı; açık onay olayı |
| `coverage_waitlist` | yeni (web-12) | Yalnız doğrulanmış hesap |
| `favorite_lists`, `favorite_list_items` | yeni (web-13) | `user_favorites` değişmedi |
| `privacy_exports` | yeni (web-10) | Elle işlenen veri kopyası talebi |
| Diğer içerik tabloları | bkz. `docs/schema/f5.md`, `docs/schema/f6.md` | Reviews, photos, claims, business_changes, feature_activations, işlem defterleri |

## Bilinen farklar
- Koordinat onaysız yayındaki kayıtlar aramaya girmez (kullanıcı talimatı). Kaynakta `searchBusiness` bunları kabul ediyordu.
- Meili yazım hatası toleransı MySQL FULLTEXT'te yoktur. Tek harfli terimler `LIKE` ile aranır, çünkü ngram tek harfi indekslemez.
- Nokta `ST_GeomFromText(..., 4326, 'axis-order=lat-long')` ile yazılır. 0003 ve sözleşmedeki `ST_SRID(POINT(boylam, enlem), 4326)` eksen sırası bakımından gerçek motorda doğrulanmadı.
- `NEXT_PUBLIC_APP_MODE=demo` dalı taşınmadı; `demo/` üretim katmanına taşınmaz.
