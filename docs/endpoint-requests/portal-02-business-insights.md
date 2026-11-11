---
platform: portal
status: done
priority: P2
area: core
screen: B01 · / · Genel bakış KPI kartları (profil görüntüleme, telefon düğmesi, yol tarifi, ortalama puan) ve "Son 30 gün etkileşim"
---
## İhtiyaç
Genel bakıştaki dört KPI kartı ve etkileşim grafiği pasif. Organik profil etkileşimi (reklamdan bağımsız) için ölçüm ve okuma ucu yok; `campaigns/metrics` yalnız kampanya içindir.

## Önerilen sözleşme
`GET /api/businesses/insights?businessId=<id>&days=30` · Bearer · aktif `owner` veya `staff` üyeliği · `days` ∈ {7,30}
→ 200
```json
{"businessId":"…","days":30,"timezone":"Europe/Istanbul","enabled":true,
 "totals":{"profileViews":0,"phoneTaps":0,"directionTaps":0},
 "rows":[{"date":"2026-11-01","profileViews":0,"phoneTaps":0,"directionTaps":0}],
 "reviews":{"count":0,"average":null},
 "definitions":{"phoneTaps":"Telefon düğmesine basma; gerçekleşen görüşme değildir."},
 "generatedAtMs":…}
```
- `enabled:false`: ölçüm toplama kapalı; değerler yalnız önceden kaydedilmiş olaylardır (kampanya ölçümüyle aynı kural).
- Bot/tekrar filtresi sunucuda; tekil kişi verisi dönmez. `reviews.average` yorum yoksa `null` (sıfır yıldız değil).
- 400 · 401 · 403 · 404 · 429.
Toplama tarafı (profil/telefon/yol tarifi olayları, web ve native) bu talebin kapsamı dışında ayrı karar gerektirebilir; yalnız okuma ucu da kabul edilebilir.

## Kaynak dayanağı
product-plan "Reklam ve sponsorluk" (gösterim, profil ziyareti, telefon, yol tarifi ayrı ölçülür; telefon düğmesi görüşme değildir), "Ölçüm ve maliyet kontrolü"; Figma K169 B01; K183 pasif "KPI kartları".

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

KISMİ uygulama: yalnız sunucuda zaten bulunan veriden salt okunur özet.
`GET /api/businesses/insights?businessId=&days=7|30` · aktif owner/staff, doğrulanmış e-posta → 200
```json
{"businessId","days":30,"timezone":"Europe/Istanbul","favorites":{"total":4},"requests":{"total":9,"byStatus":{"requested":3,…},"byKind":{"quotes":6,"appointments":3},"rows":[{"date":"2026-11-01","created":0}]},"reviews":{"count":12,"average":4.6,"awaitingReply":2},"notTracked":["profileViews","phoneTaps","directionTaps"],"generatedAtMs"}
```
- `favorites.total` ve `reviews` tüm zamanlar; `requests` seçili dönem (Europe/Istanbul günleri). `awaitingReply`: yanıtı olmayan ve bekleyen yanıtı da olmayan yayımlanmış yorum.
- REDDEDİLDİ: profil görüntüleme, telefon ve yol tarifi dokunuşları (yeni izleme gerektirir; gizlilik/çerez ve saklama kararı kullanıcıda). `notTracked` bunu açıkça bildirir; kartlar pasif kalır.
