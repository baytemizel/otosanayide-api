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

## Bilinen farklar
- Koordinat onaysız yayındaki kayıtlar aramaya girmez (kullanıcı talimatı). Kaynakta `searchBusiness` bunları kabul ediyordu.
- Meili yazım hatası toleransı MySQL FULLTEXT'te yoktur. Tek harfli terimler `LIKE` ile aranır, çünkü ngram tek harfi indekslemez.
- Nokta `ST_GeomFromText(..., 4326, 'axis-order=lat-long')` ile yazılır. 0003 ve sözleşmedeki `ST_SRID(POINT(boylam, enlem), 4326)` eksen sırası bakımından gerçek motorda doğrulanmadı.
- `NEXT_PUBLIC_APP_MODE=demo` dalı taşınmadı; `demo/` üretim katmanına taşınmaz.
