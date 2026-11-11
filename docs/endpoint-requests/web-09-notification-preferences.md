---
platform: web
status: done
priority: P1
area: core
screen: /hesabim · Bildirim ve iletişim tercihleri (S07)
---
## İhtiyaç
Üç tercih anahtarı (talep güncellemeleri, bakım hatırlatma e-postası, ürün duyuruları) pasif. Pazarlama iletisi varsayılan kapalı olmalı ve açık onay kaydı tutulmalı (İYS/ETBİS incelemesi K160).

## Önerilen sözleşme
`GET /api/me/preferences` · Bearer → 200
```json
{"version":3,"channels":{"requestUpdates":{"inApp":true,"push":false,"email":false},"reminderEmail":false,"marketing":false},"updatedAtMs":1731300000000}
```
`PATCH /api/me/preferences` · Bearer · JSON `{"expectedVersion":3,"changes":{"marketing":true}}` → 200 aynı gövde (yeni sürüm).
- `inApp` talep güncellemeleri kapatılamaz (işlem bildirimi), gövdede `false` 400.
- `reminderEmail` ve `requestUpdates.email` doğrulanmış e-posta ister (403). `marketing:true` açık onay kaydı (zaman, kaynak `web|ios|android`, metin sürümü) üretir; kapatma her zaman serbest.
- 409 sürüm çakışması · 429.
- Push tercihleri `me/push` ile ayrı kalır; bu uç push teslimini açmaz (PUSH-01 açık).

## Kaynak dayanağı
product-plan "Ürün yüzeyleri" (bildirim tercihi), "Ekran envanteri → Üyelik (bildirim tercihleri)"; REMAINING_WORK MAIL-02 (tercih işlemleri), K160 hukuk kapısı (İYS).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

`GET /api/me/preferences` · Bearer → 200
```json
{"version":3,"requestUpdates":{"inApp":true,"push":false,"email":false},"reminderEmail":true,"productNews":{"enabled":false,"consentAtMs":null,"source":null,"textVersion":null},"delivery":{"requestUpdatesPush":"not_enabled","requestUpdatesEmail":"not_enabled","productNews":"not_enabled"},"updatedAtMs":null}
```
Kayıt yoksa `version:0` ve varsayılanlar (ürün duyuruları KAPALI, hatırlatma e-postası açık).
`PATCH /api/me/preferences` · JSON (katı) `{"expectedVersion":3,"changes":{"requestUpdatesPush"?,"requestUpdatesEmail"?,"reminderEmail"?,"productNews"?,"productNewsTextVersion"?}}` → 200 aynı gövde.
- `inApp` değiştirilemez (alan yok). `productNews:true` için `productNewsTextVersion` zorunlu; geçerli değer şu an yalnız `product-news-2026-11`. Açma/kapama onay olay kaydı üretir (zaman, kaynak `web|portal|ios|android`, metin sürümü); kaynak `Origin`/`X-Client-Platform`'dan çıkarılır, belirlenemezse 400.
- E-posta gerektiren bir tercihi AÇMAK doğrulanmış e-posta ister (403). 409 sürüm · 400 boş/hatalı gövde · 429.
- Gönderim: ürün duyurusu ve talep güncellemesi e-posta/push gönderimi YAZILMADI (`delivery: not_enabled`; İYS/hukuk ve sağlayıcı kararı kullanıcıda). `reminderEmail=false` hesap düzeyinde üst anahtardır: hatırlatma başına `channels` içinde e-posta seçili olsa bile `reminders:deliver-email` göndermez ve kuyruğu `preference_changed` ile kapatır.
Önerilenden farklar: düz `changes` anahtarları; `marketing` adı `productNews`.
