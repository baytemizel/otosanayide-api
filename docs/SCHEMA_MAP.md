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
| Diğer içerik tabloları | bkz. `docs/schema/f5.md`, `docs/schema/f6.md` | Reviews, photos, claims, business_changes, feature_activations, işlem defterleri |

## Bilinen farklar
- Koordinat onaysız yayındaki kayıtlar aramaya girmez (kullanıcı talimatı). Kaynakta `searchBusiness` bunları kabul ediyordu.
- Meili yazım hatası toleransı MySQL FULLTEXT'te yoktur. Tek harfli terimler `LIKE` ile aranır, çünkü ngram tek harfi indekslemez.
- Nokta `ST_GeomFromText(..., 4326, 'axis-order=lat-long')` ile yazılır. 0003 ve sözleşmedeki `ST_SRID(POINT(boylam, enlem), 4326)` eksen sırası bakımından gerçek motorda doğrulanmadı.
- `NEXT_PUBLIC_APP_MODE=demo` dalı taşınmadı; `demo/` üretim katmanına taşınmaz.
