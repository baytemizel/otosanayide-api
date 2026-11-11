---
platform: web
status: done
priority: P1
area: core
screen: W02/W03 · / (arama sonuçları) · Sırala, sayfalama, "Bu bölgede ara"
---
## İhtiyaç
`GET /api/search` en çok 24 sonuç döndürüyor; sıralama seçimi, sonraki sayfa ve toplam yok. Ürün planı sıralama seçeneklerini (önerilen, en yakın, en yüksek değerlendirme, en çok değerlendirilen) ve "Bu bölgede ara" eylemini ister. Bugün `parseSearchRequest` bilinmeyen anahtarı 400 ile reddettiği için istemci bu parametreleri gönderemez.

## Önerilen sözleşme
`GET /api/search` (geriye uyumlu genişletme) · yetkisiz
- Yeni sorgu anahtarları (her biri en çok bir kez):
  - `sort=recommended|nearest|rating|reviews` (varsayılan `recommended`; `nearest` yalnız `lat/lng` varken, aksi 400). `rating` az yorumlu 5,0'ı çok yorumlu 4,8'in üstüne çıkarmamalı (product-plan sıralama kuralı).
  - `cursor=<opak, imzalı, ≤512 karakter>`: önceki yanıtın `nextCursor` değeri; diğer filtrelerle birlikte gönderilir, filtreler değişirse 400.
  - `bbox=minLng,minLat,maxLng,maxLat` ("Bu bölgede ara"; `lat/lng/radius` ile birlikte gönderilmez, en çok ~50 km köşegen).
- Yanıta eklenenler (mevcut alanlar aynen): `"nextCursor":"…"|null`, `"total":{"value":123,"exact":true}` (tam sayım pahalıysa `exact:false` + alt sınır).
- Sayfa boyutu 24 kalır; imleç bölge taramayı sınırlamak için hız limitine bağlıdır.
- ÖNEMLİ: `packages/core/search-client.ts` şeması `.strict()`; yeni üst düzey alanlar eklenirken web, `nextCursor`/`total` alanlarını ayrıştırıp kalanını core ayrıştırıcısına verir (web `app/lib/search-response.ts`). Native istemciler de bilinmeyen alanları yok saymalı.

## Kaynak dayanağı
product-plan "Arama akışı" (sıralama seçenekleri, "Bu bölgede ara", 26 bin kayıt tarayıcıya indirilmez), "Güvenlik ve veri koruması" (sınırlandırılmış sayfa boyutu, imzalı imleç); K183 pasifler (sıralama ve sayfalama).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Arama v2: `GET /api/search?v=2&...` — `v` yoksa yanıt ve kurallar birebir eskisi (core `search-client` katı şemasından geçer).
- Yeni sorgu anahtarları (yalnız `v=2` ile; aksi 400): `sort=recommended|nearest|rating|most_reviewed` (varsayılan `recommended`), `cursor=<opak, ≤512>`, `verifiedOwner=true`.
  - `recommended`: bugünkü sıra (konum varsa mesafe, yoksa ad). `nearest`: `lat/lng` zorunlu (aksi 400), yalnız onaylı koordinat. `most_reviewed`: yayımlanmış yorum sayısı. `rating`: Bayes ağırlıklı ortalama `(C·m + toplam)/(C + n)`, **C=5, m=3,5 — öneri, kullanıcı kabulü bekler**; az yorumlu 5,0 çok yorumlu 4,8'i geçmez.
  - `cursor`: önceki yanıtın `nextCursor`'ı; HMAC-SHA256 (`APP_KEY`) imzalı, filtre+sıralama özetine bağlı. Filtre değişirse veya imza bozuksa 400. Derinlik en çok 20 sayfa (480 kayıt).
- Yanıt: `{"items":[…],"districts":[…],"sponsored":[…],"mode":"live","sort":"…","nextCursor":"…"|null,"total":{"value":n,"exact":true}|null}`. Sayfa 24. `total` yalnız metin (`q`) ve konum yokken; aksi `null`. `sponsored` yalnız ilk sayfada.
- v2 `items` öğesi = v1 `publicBusiness` + her zaman `reviewSummary` (web-08) + varsa `openingHours` (web-07).
Önerilenden farklar: `sort=reviews` yerine `most_reviewed`; `bbox` ("Bu bölgede ara") UYGULANMADI — `lat/lng/radius` ile yapılır; istemci harita merkezinden yarıçap gönderir.
