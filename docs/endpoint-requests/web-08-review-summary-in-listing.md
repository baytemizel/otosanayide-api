---
platform: web
status: done
priority: P2
area: core
screen: W02 sonuç kartları, harita listesi
---
## İhtiyaç
Arama kartları yorum sayısı/ortalamasını bilmiyor. Kaynakta kart "Henüz yorum yok" yazıyordu; bu, yorumu olan işletme için yanlış bir iddia. Web bu turda ifadeyi kaldırdı. Kartta gerçek özet için veri gerekiyor; işletme başına ayrı `GET /api/reviews` çağrısı (24 kart) kabul edilemez.

## Önerilen sözleşme
Arama öğesine ve `GET /api/businesses/{id}` yanıtına isteğe bağlı:
`"reviewSummary":{"count":12,"average":4.6}` — yalnız yayımlanmış yorumlar; `count=0` ise `average:null`. Alan yoksa istemci yorum bilgisi göstermez.
- `average` bir ondalık basamağa yuvarlanır; sıralama kuralı (web-05 `rating`) bu ham değeri değil güven ağırlıklı değeri kullanır.
- `packages/core` `publicBusiness` `.strict()` uyarısı web-06 ile aynı.

## Kaynak dayanağı
product-plan "Arama akışı" ve "Yorumlar ve güven" (“Henüz yorum yok” bir sıfır yıldız değildir; sahte sosyal kanıt yok); K183 "Veri dürüstlüğü".

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

`reviewSummary: {"count":12,"average":4.6}` — yalnız yayımlanmış yorumlar (`public_reviews`); `count=0` ise `average:null`; ortalama bir ondalık. Yalnız `GET /api/search?v=2` öğelerinde ve `GET /api/businesses/{id}?v=2`'de döner (v1 değişmez).
- Arama projeksiyonunda kolon (`business_search.review_count/review_sum/review_score`), yorum yayımlama/gizleme/geri çekme/vaka kararı ile aynı transaction'da güncellenir. Mevcut veri: `php artisan reviews:backfill-summary` (varsayılan kuru; `--apply`, `--after`, `--limit`).
- Sıralama (web-05 `rating`) Bayes puanını kullanır; ham ortalama yalnız gösterim içindir.
