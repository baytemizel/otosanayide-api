---
platform: web
status: done
priority: P2
area: core
screen: /bildirimler · "Tümünü okundu yap", türe göre sekmeler
---
## İhtiyaç
`PATCH /api/notifications` tek kayıt (`{id,read}`) alıyor; "Tümünü okundu yap" sayfa sayfa N istek gerektirir. Sekmeler (Tümü/Talepler/Araçlarım) yalnız yüklenmiş sayfayı süzüyor.

## Önerilen sözleşme
- `POST /api/notifications/read-all` · Bearer · JSON `{"before":<ms>}` → 200 `{"updated":17}`; `before` anından önce oluşturulmuş okunmamış kayıtlar okundu olur (yeni gelenler etkilenmez).
- `GET /api/notifications?kind=request|reminder&after=…` · mevcut uca isteğe bağlı `kind` filtresi; bilinmeyen değer 400.
- 401 · 429.

## Kaynak dayanağı
product-plan K066 (talep güncellemeleri için uygulama içi bildirim), K076 (araç hatırlatmalarının bildirim kutusu); K183 pasif "Tümünü okundu yap".

## API yanıtı
API-A, 11 Kasım 2026. Uygulandı (kod hazır, yerel test geçti; canlı değil).

1. `POST /api/notifications/read-all` · Bearer · `Content-Type: application/json` · gövde (katı) `{"before":<ms, tam sayı ≥0>}`
   - 200 `{"updated":17,"more":false}`. Yalnız çağıranın kendi kutusu (`user:<uid>`); `created_at_ms < before` (ve sunucu saatinden büyük `before` sunucu saatine indirilir) olan okunmamış kayıtlar okundu olur. Çağrı başına en çok 1000 kayıt; `more:true` ise istemci aynı `before` ile tekrarlar.
   - 401 · 415 · 400 `{"error":"Bilgileri kontrol edin."}` · 409 hesap silme sürecinde · 429 (genel 60/dk).
   - İşletme kutusu (`businessId`) bu uçta yok; önerilmemişti.
2. `GET /api/notifications?kind=request|reminder` — mevcut uca isteğe bağlı süzgeç; `after`/`businessId` ile birlikte çalışır. Bilinmeyen değer 400 `{"error":"Geçersiz istek."}`. `request` = `kind` alanı olmayan (talep) kayıtları.

Önerilenden fark: yanıta `more` alanı eklendi (sınırlı toplu yazım). Origin denetimi yok (kaynaktaki `/api/notifications` gibi); web ve portal CORS alır.
Test: `tests/Feature/A5AuthSqliteTest.php`.
