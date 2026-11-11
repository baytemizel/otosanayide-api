---
platform: web
status: done
priority: P2
area: core
screen: Y01 /yonetim Genel bakış (KPI, iş kuyruğu), Y02 konum inceleme, Y03 içe aktarma/mükerrer birleştirme, Y07 özellik bayrakları
---
## İhtiyaç
Yönetim panelinde KPI kartları, "İş kuyruğu → Tümü", konum (pin) inceleme eylemleri, içe aktarma KPI'ları, mükerrer birleştirme ve özellik bayrakları tablosu pasif. Mevcut admin uçları kuyruk bazlı; özet ve bu işlemler için uç yok.

## Önerilen sözleşme
1. `GET /api/admin/overview` · Bearer (admin rolü, MFA) → `{"queues":{"claims":3,"reviews":7,"reviewPhotos":2,"replies":1,"businessChanges":4,"reviewCases":0,"businessPhotos":0,"privacy":1},"generatedAtMs":…}`; yalnız rolün görebildiği kuyruklar döner. Gelir/finans sayısı yok (finans rolü ayrı).
2. Konum inceleme: `GET /api/admin/locations?after=` → `{"items":[{"businessId","name","address","current":{lat,lng,status},"suggested":{lat,lng,source}|null}],"next"}`; `PATCH /api/admin/locations` `{"businessId","expectedVersion","action":"accept_suggested|set|reject","point?":{lat,lng},"reason"}`. Google merkezi işletme noktası sayılmaz (DATA-02).
3. Mükerrer: `GET /api/admin/duplicates?after=` → aday kümeleri; `POST /api/admin/duplicates/merge` `{"survivorId","mergedIds":[…],"reason","operationId"}` → eski kimlikler yönlendirme olarak korunur, yorum/geçmiş taşınır; `POST /api/admin/duplicates/dismiss`.
4. Özellik bayrakları: `GET /api/admin/flags` (salt okunur liste: ad, değer, kaynak `env`) — yazma bu talebin dışında (bayraklar dağıtım yapılandırmasıdır).

Her madde ayrı kabul/ret alabilir.

## Kaynak dayanağı
product-plan "Ekran envanteri → Admin" (operasyon özeti, mükerrer birleştirme, konum inceleme, özellik bayrakları), "Admin operasyonu"; REMAINING_WORK DATA-02/DATA-03; K183 pasifler (KPI kartları, mükerrer birleştirme, özellik bayrakları).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

KISMİ uygulama.
1. UYGULANDI: `GET /api/admin/overview` · yönetici (rol + doğrulanmış e-posta) → `{"queues":{"claims","reviews","reviewPhotos","replies","businessChanges","reviewCases","businessPhotos","privacy","privacyExports","businessReports"},"generatedAtMs"}`. Finans/gelir sayısı yok.
4. UYGULANDI: `GET /api/admin/flags` → `{"flags":[{"name":"PUSH_DELIVERY_ENABLED","key":"push_delivery","enabled":false,"source":"env"}],"workers":[{"key","enabled","source":"env"}],"pushDriver":"log|live","writable":false}`. Değer değiştirme yok, sır/anahtar dönmez.
2. REDDEDİLDİ (konum inceleme) ve 3. REDDEDİLDİ (mükerrer birleştirme): DATA-02 (Google merkezi noktası işletme noktası sayılmaz, konum doğrulama kaynağı kararı) ve kimlik birleştirmenin veri modeli (yönlendirme kimlikleri, yorum/geçmiş taşıma, geri alma) kararlaştırılmadı. Katalog düzeltmeleri mevcut `admin/catalogue` ve `admin/business-changes` akışlarıyla yapılır.
Her iki uç `/api/admin` altında; portal origin'i CORS almaz.
