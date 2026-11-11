---
platform: web
status: done
priority: P2
area: core
screen: W02/W03 · / (filtre paneli: sayılar, ödeme/durum/olanak filtreleri)
---
## İhtiyaç
Filtre panelinde yalnız "Kart kabul ediyor" çalışıyor. Taksit, nakit, hafta sonu açık, sadece doğrulanmış, sanayi sitesi içinde, bekleme alanı, WC, erişilebilir giriş satırları ve filtre sayıları veri/uç olmadığı için pasif.

## Önerilen sözleşme
`GET /api/search` genişletmesi (web-05 ile aynı uç):
- Yeni boolean anahtarlar (yalnız `true` gönderilir): `cash`, `installments`, `verified`, `industrialSite`, `waitingArea`, `wc`, `accessible`, `weekend` (weekend yapılandırılmış saat ister, bkz. web-07).
- Yanıta isteğe bağlı `"facets":{"categories":{"mechanical":12,…},"services":{"Fren":4,…},"brands":{"BMW":3,…},"flags":{"card":7,"cash":2,"verified":5,…}}`; sayılar o anki filtreler (ilgili boyut hariç) altında, yayımlanmış ve örnek olmayan kayıtlar üzerinden. Hesaplanamayan boyut yanıtta yer almaz; istemci yalnız gelen sayıyı gösterir.
- İşletme DTO'suna (arama sonucu ve `GET /api/businesses/{id}`) değerleri yalnız veri varsa eklenen `cash`, `installments`, `waitingArea`, `accessible`, `industrialSite` (boolean; bilinmiyorsa alan yok). `cardPayment`/`wc` ile aynı üç değerli model (evet/hayır/bilinmiyor).
- `packages/core` `publicBusiness` şeması `.strict()`: bu alanlar API'ye eklenmeden önce core şeması (ve native ayrıştırıcılar) güncellenmeli; aksi halde mevcut istemciler yanıtı reddeder. Web bunları `search-response.ts` içinde ayrı okur.

## Kaynak dayanağı
product-plan "Kategori ve filtre modeli" (ek filtreler, evet/hayır/bilinmiyor), "İşletme profili" (olanaklar/ödeme); K183 pasifler (filtre sayıları, ödeme/durum/olanak filtreleri).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

KISMİ uygulama.
- UYGULANDI: `GET /api/search?v=2&verifiedOwner=true` — yalnız sahipliği doğrulanmış işletmeler (mevcut `card` ve diğer filtrelerle birlikte).
- REDDEDİLDİ: `cash`, `installments`, `waitingArea`, `wc` (filtre olarak), `accessible`, `industrialSite`, `weekend`. Gerekçe: katalogda bu alanlar yapılandırılmış veri olarak yok; değer ancak işletme beyanı veya yeni veri kaynağıyla doğabilir ve bu kaynak/doğrulama kararı kullanıcıdadır. Uydurulmuş "bilinmiyor = hayır" filtresi yanlış sonuç verir. (`weekend`, yapılandırılmış saatler yaygınlaşınca yeniden değerlendirilebilir.)
- REDDEDİLDİ: `facets` sayıları. Gerekçe: FULLTEXT + mekânsal filtre altında boyut başına GROUP BY maliyeti gerçek veride ölçülmedi (bağlantısız ortam); "ucuz" olduğu doğrulanamadı. `v=2`'de `total` yalnız metin/konum yokken verilir.
İstemci: reddedilen satırlar K183 kuralıyla pasif kalır.
