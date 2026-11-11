---
platform: web
status: done
priority: P1
area: core
screen: W02 kartlar, W04 profil "Çalışma saatleri", filtre "Şu an açık"
---
## İhtiyaç
`hours` serbest metin (≤300). Açık/kapalı durumu hesaplanamıyor; K183 bu yüzden listede ve profilde açık/kapalı göstermiyor. Ürün planı: "Çalışma saati bilinmiyorsa açık olduğu varsayılmaz".

## Önerilen sözleşme
İşletme DTO'suna (arama öğesi, sponsorlu öğe içindeki `business`, `GET /api/businesses/{id}`) yalnız doğrulanmış/yapılandırılmış veri varsa:
```json
"openingHours": {
  "timezone": "Europe/Istanbul",
  "week": [{"day":1,"intervals":[{"open":"09:00","close":"18:30"}]}, …],
  "exceptions": [{"date":"2026-12-31","intervals":[]}],
  "updatedAtMs": 1731300000000
}
```
- `day` ISO (1=Pazartesi … 7=Pazar); listede olmayan gün kapalı sayılır; `close` > `open`, gece yarısını aşan aralık iki parçaya bölünür; en çok 3 aralık/gün; `exceptions` en çok 30 kayıt, yalnız bugünden sonraki 60 gün.
- Saat bilinmiyorsa alan HİÇ gelmez (boş hafta "her gün kapalı" anlamına geldiği için gönderilmez). Serbest metin `hours` geriye uyum için kalır.
- Arama filtresi: `openNow=true` (sunucu saatine göre, Europe/Istanbul); `weekend=true` (Cumartesi veya Pazar en az bir aralık).
- Web açık/kapalı durumunu istemcide `openingHours`'tan hesaplar; `openNow` alanı DTO'da istenmez (önbellekte bayatlar).
- `packages/core` `publicBusiness` `.strict()` uyarısı web-06 ile aynı.

## Kaynak dayanağı
product-plan "İşletme profili" (açık/kapalı bilgisi, saat bilinmiyorsa açık varsayılmaz), "Kategori ve filtre modeli" (açık şimdi, hafta sonu); K183 "Liste ve profildeki açık/kapalı durumu saat verisi yapılandırılmış olmadığından gösterilmez".

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Okuma: `openingHours` yalnız yeni parametreyle döner (eski yanıtlar değişmez): `GET /api/businesses/{id}?v=2` ve `GET /api/search?v=2` öğeleri.
```json
"openingHours":{"timezone":"Europe/Istanbul","week":[{"day":1,"intervals":[{"open":"09:00","close":"18:30"}]}],"exceptions":[{"date":"2026-12-31","intervals":[]}],"updatedAtMs":1794312000000}
```
- `day` ISO 1–7; listede olmayan gün kapalı; aralık aynı gün içinde, `close` en geç `24:00`; günde ≤3 aralık; ≤60 istisna, geçmiş istisnalar yanıttan ayıklanır. Yapılandırılmış saat yoksa alan HİÇ gelmez; istemci açık varsaymaz. Sunucu "şu an açık" hesaplamaz.
- `GET /api/businesses/{id}?v=2` ayrıca `reviewSummary` döner. `v` yalnız `2` olabilir (aksi 400).
- Serbest metin `hours` aynen kalır. Yazma: portal-04.
REDDEDİLDİ: arama filtreleri `openNow`, `weekend` (veri yaygın değil; web-06).
