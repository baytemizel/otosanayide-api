---
platform: web
status: done
priority: P1
area: core
screen: S08 · /servis/[id] · "Yanlış bilgi bildir" modalı
---
## İhtiyaç
Profilde "Yanlış bilgi bildir" girişi var ama gönderim ucu yok (K183 "S08 modalı yazılmadı"). Ziyaretçi/üye; kapanmış, taşınmış, yanlış telefon/adres/konum/saat/hizmet bildirebilmeli. Bildirim yöneticinin `admin/business-changes` benzeri kuyruğuna düşer; profili doğrudan değiştirmez.

## Önerilen sözleşme
`POST /api/businesses/{id}/reports` · Bearer zorunlu (e-postası doğrulanmış üye; kötüye kullanımı sınırlamak için) · JSON
- Gövde `{"reason":"closed|moved|phone|address|location|hours|services|other","note":"…","operationId":"<uuid>"}` · `note` 0–1000 karakter, `reason=other` iken en az 10 karakter. Kişisel veri/URL kuralları yorum metniyle aynı (content-moderation).
- 201 `{"id":"<uuid>","status":"submitted"}`; aynı `operationId` tekrarında aynı yanıt (idempotent).
- 400 gövde · 401 · 403 e-posta doğrulanmamış · 404 işletme yok/yayında değil · 409 aynı kullanıcı aynı işletme için açık bildirim (ilk kaydın `id`si dönebilir) · 429 (kullanıcı başına 10/gün).
- Örnek işletmeler (`sample=true`) 404.
- Yönetici tarafı (bu talebin dışında, ayrı karar): `GET/PATCH /api/admin/business-reports`.

## Kaynak dayanağı
product-plan "İşletme profili" (… güncelleme tarihi → yanlış bilgi bildir → bu işletme sizin mi?), "Ekran envanteri → İşletme: yanlış bilgi bildirimi"; K183 Uygulanmayan ekranlar (S08).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

`POST /api/businesses/{id}/reports` · Bearer · e-postası doğrulanmış üye (anonim gönderim YOK) · JSON (katı) `{"reason":"closed|moved|phone|address|location|hours|services|other","note"?:"0-1000","operationId":"<uuid>"}`; `reason=other` iken `note` en az 10 karakter.
- 201 `{"id":"<uuid>","businessId","reason","status":"submitted","createdAtMs"}`; aynı `operationId` + aynı içerik 200 aynı kayıt, farklı içerik 409.
- `note` bağlantı, e-posta, 10+ haneli numara veya yerel moderasyon işareti taşırsa 400 `{"error":"Notta bağlantı, iletişim bilgisi veya uygunsuz ifade olmamalı."}`.
- 400 gövde/sorgu · 401 · 403 doğrulanmamış e-posta · 404 işletme yok/yayında değil/örnek · 409 aynı kullanıcının aynı işletmede açık bildirimi · 429 (kullanıcı başına 10/gün).
- Profil DEĞİŞMEZ. Yönetici: `GET /api/admin/business-reports?status=submitted|resolved|dismissed&after=<id>` → `{"items":[{id,businessId,businessName,reason,note,status,version,resolution,createdAtMs,updatedAtMs}],"next"}`; `PATCH /api/admin/business-reports` `{"id","expectedVersion","decision":"resolved|dismissed","resolution":"10-1000"}` → `{"ok":true}`; kendi bildirimi 403, değişmişse 409. Bildirimi yapanın kimliği yöneticiye dönmez.
Önerilenden fark: anonim yol yok (orkestratör kararı izin veriyordu; daha sıkı seçildi).
