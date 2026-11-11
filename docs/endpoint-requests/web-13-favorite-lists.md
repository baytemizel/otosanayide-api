---
platform: web
status: done
priority: P2
area: core
screen: /kaydedilenler · "Yeni liste", adlı listeler; karşılaştırma "Karşılaştırmayı kaydet"
---
## İhtiyaç
Favoriler tek düz liste (`me` → `favorites`). Ürün planı "favori listeleri" ve "en fazla üç işletmeyi karşılaştırma" ister. Karşılaştırma web'de istemci tarafında çalışıyor; kaydetmek ve listeler için sunucu kaydı gerekiyor (cihazlar arası).

## Önerilen sözleşme
`GET /api/me/lists` · Bearer → `{"items":[{"id","name","businessIds":[…],"kind":"list|comparison","version","updatedAtMs"}]}` (en çok 20 liste, liste başına 100 işletme, `comparison` en çok 3).
`POST /api/me/lists` · `{"name":"…(1–60)","kind":"list|comparison","businessIds":[…],"operationId":"<uuid>"}` → 201 kayıt.
`PATCH /api/me/lists` · `{"id","expectedVersion","name?","add?":[…],"remove?":[…]}` → 200 kayıt · 409 sürüm.
`DELETE /api/me/lists?id=…` → 204.
- Mevcut `favorites` alanı "Kaydedilenler" varsayılan listesi olarak kalır (geriye uyum). Yayından kalkan işletme listede kalır, yanıtta `unavailableIds` ile işaretlenir.

## Kaynak dayanağı
product-plan "Ücretsiz üye özellikleri" (favori listeleri, en fazla üç işletmeyi karşılaştırma, kayıtlı aramalar); K183 pasifler (adlı listeler).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Mevcut düz favoriler (`/api/me/favorites`, `GET /api/me` içindeki `favorites`) DEĞİŞMEZ.
- `GET /api/me/lists` → `{"items":[{"id","name","kind":"list|comparison","businessIds":[…],"unavailableIds":[…],"version","createdAtMs","updatedAtMs"}]}`.
- `POST /api/me/lists` `{"name":"1-60","kind","businessIds":[…],"operationId":"<uuid>"}` → 201 (aynı operationId 200 aynı kayıt).
- `PATCH /api/me/lists` `{"id","expectedVersion","name"?,"add"?:[…],"remove"?:[…]}` → 200 kayıt · 409 sürüm · 404.
- `DELETE /api/me/lists?id=<uuid>` → 204.
- Sınırlar: 20 liste, liste başına 100, `comparison` en çok 3. Eklenen işletme yayında ve örnek olmayan olmalı (400). Sonradan yayından kalkan `unavailableIds` ile işaretlenir.
