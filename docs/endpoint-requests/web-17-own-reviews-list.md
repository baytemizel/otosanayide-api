---
platform: web
status: done
priority: P1
area: core
screen: /yorumlarim · Yorumlarım (K061 kendi yorumunu takip, K063 düzenleme ve geri çekme)
---
## İhtiyaç
`GET /api/reviews/mine` yalnız `businessId` ile tek işletmedeki kendi yorumunu döndürüyor. Üye alanındaki "Yorumlarım" ekranı kullanıcının bütün yorumlarını (durum, düzenleme/geri çekme hakkı, işletme adı) listeleyemiyor. Web bu turda geçici olarak yalnız kaydedilen (favori) işletmeleri tek tek soruyor; favoride olmayan işletmedeki yorum görünmüyor.

## Önerilen sözleşme
`GET /api/reviews/mine/all?after=<opak imleç>` · Bearer · e-postası doğrulanmış yazar (mevcut `mine` ile aynı kural)
- 200 `{"items":[{"businessId":"…","businessName":"…","updatedAtMs":1731300000000,"review":<mevcut mine yanıtındaki review nesnesi: id,text,rating,version,status,reason,resolved,wc,canEdit,canWithdraw,… >}],"next":"…"|null}`; en yeni güncellenen önce, sayfa başına 20.
- Geri çekilmiş yorumlar `status:"withdrawn"` ile listede kalır (geçmiş), silinmiş/anonimleştirilmiş kayıtlar listelenmez.
- Yayından kalkmış işletmede `businessName` son bilinen ad, `businessAvailable:false`.
- 401 · 403 (doğrulanmamış e-posta, mevcut `mine` ile aynı mesaj) · 400 imleç · 429.
- Düzenleme ve geri çekme mevcut `PATCH /api/reviews/mine` ile kalır (değişiklik yok).

## Kaynak dayanağı
product-plan K061 "Kendi yorumunu takip etme", K063 "Kendi yorumunu düzenleme ve geri çekme", "Ekran envanteri → Kişisel alan: yorumlarım, katkılarım".

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

`GET /api/reviews/mine/all?after=<opak imleç>` · Bearer · doğrulanmış e-posta (403 `{"error":"E-posta doğrulaması gerekli."}`)
- 200 `{"items":[{"businessId","businessName","businessAvailable":bool,"updatedAtMs","review":<GET /api/reviews/mine içindeki review nesnesiyle aynı projeksiyon>}],"next":"…"|null}`; son güncellenen önce, sayfa 20. Geri çekilenler `status:"withdrawn"` ile kalır.
- 400 imleç/sorgu · 401 · 429. Düzenleme/geri çekme `PATCH /api/reviews/mine` ile aynen.
Önerilenden fark: yok.
