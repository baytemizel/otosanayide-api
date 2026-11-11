---
platform: portal
status: done
priority: P2
area: core
screen: B06 · /randevular · Kapasite paneli (eşzamanlı araç, slot süresi, onay modeli, yeni randevu alımı) ve "İzin / bayram ekle"
---
## İhtiyaç
Randevu takvimi yalnız saat önerilmiş talepleri (`GET /api/requests`) gösteriyor. Kapasite, slot süresi, onay modeli, randevu alımını duraklatma ve izin/bayram kapanışları için uç yok; panel pasif. Ürün planı randevu için kapasite ve çalışma takvimini görünürlük koşulu sayıyor.

## Önerilen sözleşme
1. `GET /api/businesses/capacity?businessId=<id>` · Bearer · aktif `owner`/`staff` → 200
   `{"businessId","version":2,"timezone":"Europe/Istanbul","concurrentVehicles":2,"slotMinutes":60,"approval":"business_confirms","acceptingAppointments":true,"closures":[{"id":"<uuid>","from":"2026-12-31","to":"2027-01-01","reason":"holiday|leave|other","note":""}]}`
2. `PATCH /api/businesses/capacity` · yalnız `owner` · `{"businessId","expectedVersion","concurrentVehicles":1..20,"slotMinutes":30|60|90|120,"approval":"business_confirms","acceptingAppointments":boolean}` → 200 `{"version"}`. İlk sürümde `approval` yalnız `business_confirms` (kesin anlık rezervasyon ayrı karar).
3. `POST /api/businesses/capacity/closures` `{"businessId","from","to","reason","note","operationId"}` → 201 `{"id","version"}`; `DELETE /api/businesses/capacity/closures?businessId=&id=` → 204.
- `acceptingAppointments:false` yalnız yeni talepleri durdurur; mevcut randevular kaybolmaz.
- Kayıtlar UTC, gün sınırları Europe/Istanbul. 400 · 401 · 403 · 409 sürüm · 429.

## Kaynak dayanağı
product-plan "Teklif ve randevu → Randevu" (kapasite ve çalışma takvimi gerekir; bayram, izin, mola ve çalışan kapasitesi takvime işlenir; özellik kapatılınca mevcut randevular kaybolmaz), K065; Figma K169 B06; K183 pasif "takvim saat seçimi".

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

- `GET /api/businesses/capacity?businessId=` · aktif owner/staff → `{"businessId","timezone":"Europe/Istanbul","configured":bool,"version","slotMinutes":60|120,"capacity":1-10,"approval":"manual|auto_offer","accepting":bool,"closures":[{"id","from","to","reason":"holiday|leave|other","note"}]}` (ayar yoksa `configured:false`, `accepting:false`).
- `PATCH /api/businesses/capacity` · yalnız owner (+ sahiplik doğrulanmış) `{"businessId","expectedVersion","slotMinutes":60|120,"capacity":1..10,"approval":"manual|auto_offer","accepting":bool}` → 200 güncel görünüm · 409 sürüm.
- `POST /api/businesses/capacity/closures` `{"businessId","from","to","reason","note"?}` → 201; tarih bugün..+366 gün; en çok 60. `DELETE /api/businesses/capacity/closures?businessId=&id=` → 204.
- Herkese açık müsaitlik: web-15 madde 3. Talep oluşturmada (randevu): ayar varsa alım kapalıysa veya gün kapalıysa 409; `preferredSlot` verildiyse saat ve kapasite denetlenir. Ayar yoksa eski davranış.
- `approval:"auto_offer"`: saat seçilmiş talep anında `offered` durumunda otomatik öneriyle oluşur; randevu ancak müşteri kabul edince kesinleşir. Kabulde kapasite kilitli sayımla yeniden denetlenir (dolu 409).
Önerilenden farklar: slot 30/90 dk YOK (mevcut teklif kuralı tam saat ister); `approval` değerleri `manual|auto_offer` (anlık kesin rezervasyon yok); `concurrentVehicles` adı `capacity`.
