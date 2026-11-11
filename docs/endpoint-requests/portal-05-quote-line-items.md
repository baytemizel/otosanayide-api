---
platform: portal
status: done
priority: P2
area: core
screen: B05 · /talepler · Ön teklif formu (kalem tablosu işçilik/parça/KDV, geçerlilik, dahil olmayanlar, "ön inceleme sonrası değişebilir")
---
## İhtiyaç
`PATCH /api/requests` `action:"offer"` yalnız `text` ve tek `price` (veya `startsAt`) alıyor. Teklif formundaki kalem tablosu, geçerlilik süresi seçimi, "dahil olmayan" alanı ve koşul kutusu pasif.

## Önerilen sözleşme
`PATCH /api/requests` · mevcut `offer` eylemine isteğe bağlı alanlar (teklif türünde):
```json
{"id","version","action":"offer","text":"…",
 "price":1234.5,
 "lines":[{"kind":"labour|parts|tax|other","label":"…","amountMinor":120000}],
 "currency":"TRY","validDays":3,"excluded":"…","mayChangeAfterInspection":true}
```
- `lines` verilirse `price` = satırların toplamı/100 olmalı (sunucu doğrular; 400 aksi halde). En fazla 20 satır; `label` ≤ 120.
- `validDays` ∈ {1,3,7}; `offer.expiresAtMs` bundan hesaplanır (bugünkü sabit süre yerine).
- `GET /api/requests` `offer` nesnesinde aynı alanları döndürür; kullanıcı tarafı bunları "ön inceleme sonrası değişebilir" uyarısıyla gösterir.
- Kullanıcı kabul etmeden fiyat değişikliği onay sayılmaz (mevcut kural).

## Kaynak dayanağı
product-plan "Teklif ve randevu → Teklif" (işçilik/parça/vergi, dahil olmayan işlemler, geçerlilik süresi ve "ön inceleme sonrası değişebilir" koşulu), K065; Figma K169 B05.

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

`PATCH /api/requests` `action:"offer"` (yalnız `kind=quotes`) gövdesine isteğe bağlı: `"lines":[{"kind":"labour|parts|tax|other","label":"1-120","amountMinor":>=1}]` (1–20 satır), `"currency":"TRY"`, `"validDays":1|3|7`, `"excluded":"≤500"`, `"mayChangeAfterInspection":bool`.
- `lines` varsa toplam sunucuda hesaplanır (kuruş); `price` gönderilmezse `toplam/100` yazılır, gönderilirse eşit olmalı (aksi 409 `{"error":"Teklif toplamı kalemlerle uyuşmuyor."}`). `validDays` `expiresAtMs`'i belirler (varsayılan 7). Randevu talebinde bu alanlar 409.
- Okuma yalnız `GET /api/requests?v=2[&businessId=…]`: `offer` nesnesine `lines`, `totalMinor`, `currency`, `excluded`, `mayChangeAfterInspection` (varsa) ve talebe `preferredStartsAt` (varsa) eklenir. `v` olmadan yanıt değişmez.
Önerilenden fark: tutarsız toplam 400 değil 409 (mevcut teklif kuralı hataları 409).
