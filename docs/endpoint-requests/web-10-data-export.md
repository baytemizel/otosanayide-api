---
platform: web
status: done
priority: P1
area: core
screen: /hesabim · Verilerini indir
---
## İhtiyaç
"Veri kopyası iste" pasif. `me/privacy` yalnız silme kapsamlarını (`garage`, `account`) tanıyor; KVKK erişim hakkı için kopya talebi yok.

## Önerilen sözleşme
`POST /api/me/exports` · Bearer · JSON `{"operationId":"<uuid>"}` → 202 `{"id":"<uuid>","status":"queued","createdAtMs":…}` (aynı operationId tekrarında aynı yanıt).
`GET /api/me/exports` · Bearer → 200 `{"items":[{"id","status":"queued|ready|expired|failed","createdAtMs","readyAtMs":null|…,"expiresAtMs":null|…}],"next":null}` (en çok 5 kayıt).
`GET /api/me/exports/{id}/download` · Bearer → 200 `application/zip` (veya JSON), `Content-Disposition: attachment`, `Cache-Control: no-store`; hazır değilse 409, süresi geçtiyse 410.
- E-posta doğrulanmış olmalı (403). Aynı anda tek açık talep (409), günde 1 (429).
- İçerik: hesap, araç/bakım/hatırlatma, favoriler, yorumlar/fotoğraf meta verisi, talepler, gizlilik talepleri. İşletme ve finans kayıtları kapsam dışı (ERA-07 saklama kararı).
- Hazır olduğunda uygulama içi bildirim (`notifications`, yeni `kind: "export_ready"`).

## Kaynak dayanağı
product-plan "Ekran envanteri → Üyelik (hesap silme ve veri talebi)", "Güvenlik ve veri koruması" (KVKK); K160 hukuk kapısı (hak-silme operasyonu).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Otomatik paket üretimi YOK; talep operatör tarafından işlenir. `DATA_REQUESTS_ENABLED` kapalıyken 503 `{"error":"Veri talepleri henüz kullanıma açılmadı."}`.
- `GET /api/me/privacy/exports` · Bearer (doğrulanmış) → `{"items":[{"id","status":"submitted|in_progress|delivered|rejected|cancelled","version","reason":null|"…","createdAtMs","updatedAtMs"}],"preparation":"manual"}` (en yeni 5).
- `POST /api/me/privacy/exports` `{"operationId":"<uuid>"}` → 202 `{"request":{…},"preparation":"manual"}` (aynı operationId 200). Son 10 dakikada giriş şartı (428, `me/privacy` ile aynı). Açık talep varken 409; 24 saatte bir (429).
- `PATCH /api/me/privacy/exports` `{"id","expectedVersion","action":"cancel"}` → yalnız `submitted` iken.
- `preparation:"manual"` ve `status:"submitted"` kopyanın hazırlandığı anlamına GELMEZ; `delivered` operatörün kopyayı kanal dışı teslim ettiğini işaretler. İndirme ucu yok.
- Yönetici kuyruğu `admin/privacy` içinde, yalnız yeni parametreyle: `GET /api/admin/privacy?kind=export&status=submitted|in_progress&after=<id>` → `{"items":[…+ownerUid],"next"}`; `POST /api/admin/privacy` `{"kind":"export","requestId","expectedVersion","action":"start|deliver|reject","reason"?}` (`reject` için `reason` ∈ `identity_unverified|duplicate|legal_hold|other`).
Önerilenden farklar: `me/privacy` silme talepleriyle aynı kayda karışmaz (eski istemcilerin katı şeması bozulmasın); ayrı yol `me/privacy/exports`; indirme ve bildirim yok.
