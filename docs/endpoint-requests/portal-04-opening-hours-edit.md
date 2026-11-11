---
platform: portal
status: done
priority: P2
area: core
screen: B03 · /konum · "Çalışma saatleri" ve "Tatil ve izinler" sekmeleri
---
## İhtiyaç
Okuma tarafı web talebi `web-07-opening-hours.md` ile açık (`openingHours` DTO'su). Portal bu verinin yazma tarafına ihtiyaç duyuyor: işletme gün gün aralık ve istisna günleri girmeli. Bugün `hours` serbest metin olarak `PATCH /api/businesses/manage` profil revizyonu içinde gidiyor; "Tatil ve izinler" sekmesi pasif.

## Önerilen sözleşme
`PATCH /api/businesses/manage` gövdesindeki `profile` nesnesine isteğe bağlı alan (mevcut revizyon/inceleme akışı aynen geçerli):
```json
"openingHours": {"timezone":"Europe/Istanbul",
  "week":[{"day":1,"intervals":[{"open":"09:00","close":"18:30"}]}],
  "exceptions":[{"date":"2026-12-31","intervals":[]}]}
```
- `day` 1–7 (ISO, Pazartesi=1), aralıklar çakışmaz, `open < close`, gece yarısını aşan aralık iki güne bölünür; en fazla 3 aralık/gün, 60 istisna.
- `GET /api/businesses/manage` her işletmede ve `change.profile` içinde aynı alanı döndürür (yoksa `null`).
- Onaylanan revizyon `web-07` DTO'sunu besler. Serbest metin `hours` geriye uyumluluk için kalır.
- Hatalar mevcut `businessChangeSchema` hatalarıyla aynı (400/403/409).
Ayrı uç tercih edilirse: `PUT /api/businesses/hours` `{businessId, expectedVersion, openingHours}` (yine incelemeye düşer).

## Kaynak dayanağı
`web-07-opening-hours.md` (okuma tarafı), product-plan "Çalışma saati bilinmiyorsa açık olduğu varsayılmaz", Figma K169 B03, K183 "açık/kapalı durumu saat verisi yapılandırılmış olmadığından gösterilmez".

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Yazma mevcut inceleme akışında: `PATCH /api/businesses/manage` gövdesine (profil nesnesinin DIŞINDA, üst düzey) isteğe bağlı `openingHours`:
```json
{"businessId","expectedVersion","expectedRevisionId","profile":{…aynen…},"openingHours":{"timezone":"Europe/Istanbul","week":[…],"exceptions":[…]}}
```
- Alan yoksa saatler değişmez; `null` saatleri kaldırır; nesne yeni saatleri önerir. Doğrulama web-07 kuralları + istisna tarihleri bugün..+366 gün; çakışan/sırasız aralık, tekrarlanan gün/tarih 400.
- Onay yöneticinin mevcut `PATCH /api/admin/business-changes` approve kararıyla birlikte uygulanır (ret: uygulanmaz).
- Okuma `?v=2` ile: `GET /api/businesses/manage?v=2[&businessId=…]` her işletmeye `openingHours` (yayındaki; yoksa `null`) ve `pendingOpeningHours` (`null` ya da `{"action":"set|clear","value":…|null}`) ekler. Yönetici `GET /api/admin/business-changes?v=2` her öğeye aynı iki alanı ekler. `v` olmadan yanıtlar değişmez.
Önerilenden farklar: alan `profile` içinde değil üst düzeyde (profil şeması ve onay ayrıştırması değişmesin diye); `change.profile` içinde değil ayrı `pendingOpeningHours`.
