---
platform: web
status: done
priority: P2
area: core
screen: W04 yorum kartı "Faydalı"; W15/W16 talep ayrıntısı "Mesaj yaz…", teklif kalemleri; W14 randevu gün/saat seçimi
---
## İhtiyaç
Üç küçük pasif grup, hepsi veri/uç eksikliğinden:
1. Yorum kartındaki "Faydalı" oyu.
2. Talep ayrıntısında ek bilgi yazışması (product-plan Talep: "ek bilgi yazışması") ve teklif kalem tablosu.
3. Randevu formunda takvim/saat seçimi (kapasite ve çalışma takvimi gerekir).

## Önerilen sözleşme
1. `PUT /api/reviews/{id}/helpful` · Bearer → 200 `{"helpful":true,"count":5}`; `DELETE` aynı yol → 200 `{"helpful":false,"count":4}`. Kendi yorumuna 403. Sayı yalnız ≥3 iken herkese gösterilir (manipülasyon riski).
2. `GET /api/requests/{id}/messages?after=` · Bearer (talep sahibi veya yetkili şube) → `{"items":[{"id","author":"customer|business","text","createdAtMs"}],"next"}`; `POST` aynı yol `{"text":"1–1000","operationId"}` → 201. Kapanmış talepte 409. Teklif kalemleri: talep görünümüne `quote:{"lines":[{"label","amountMinor"}],"currency":"TRY","validUntilMs","excludes"}` (işletme yazınca).
3. `GET /api/businesses/{id}/availability?from=YYYY-MM-DD&days=7` · yetkisiz → `{"timezone":"Europe/Istanbul","days":[{"date","slots":["09:00","09:30"]}]}`; yalnız `features.appointments=true` şubede, aksi 404. `POST /api/requests` randevu gövdesine `slot:"YYYY-MM-DDTHH:mm"` (sunucu kapasiteyi yeniden denetler, 409).

Her biri ayrı kabul/ret alabilir; yanıtta madde numarasıyla belirtilmesi yeterli.

## Kaynak dayanağı
product-plan "Yorumlar ve güven", "Teklif ve randevu", "Ekran envanteri → Talep"; K065 teklif ve randevu operasyonu; K183 pasifler (takvim saat seçimi).

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Madde bazında:
1. "Faydalı" oyu — REDDEDİLDİ: `docs/product-plan.md` içinde dayanağı yok (yorum etkileşimi/oylama tanımlanmamış). Düğme pasif kalır.
2. Yazışma — UYGULANDI. `GET /api/requests/messages?id=<talep>&after=<mesaj id>` → `{"items":[{"id","author":"customer|business","text","createdAtMs"}],"next"}` (eskiden yeniye, 50). `POST /api/requests/messages` `{"id","text":"1-1000","operationId":"<uuid>"}` → 201 mesaj (aynı operationId 200). Taraflar: talep sahibi ve talebi yönetebilen işletme üyesi (diğerleri 403). Kapanmış talep (iptal/ret/tamamlandı) 409; talep başına 100 mesaj; bağlantı/iletişim bilgisi/uygunsuz ifade 400. Bildirim üretmez. Teklif kalemleri: portal-05.
3. Müsaitlik — UYGULANDI (portal-03 ile). `GET /api/businesses/{id}/availability?from=YYYY-MM-DD&days=1..14` · yetkisiz → `{"timezone":"Europe/Istanbul","slotMinutes":60|120|null,"days":[{"date","slots":["09:00",…]}]}`. Randevu özelliği aktif değilse, ayar yoksa, alım duraklatılmışsa veya yapılandırılmış saat yoksa `{"slotMinutes":null,"days":[]}`. Kapalı gün boş liste; bugünden önce `from` 400; en çok 90 gün ileri. `POST /api/requests` randevu gövdesine isteğe bağlı `"preferredSlot":"HH:00"` (yalnız `kind=appointments` + `preferredDate`); sunucu duraklatma, kapalı gün, saat ve kapasiteyi yeniden denetler (409). Talep göndermek randevunun onaylandığı anlamına gelmez.
Önerilenden farklar: yazışma yolu `requests/{id}/messages` değil `requests/messages?id=` (mevcut `requests` uçlarıyla aynı biçim); slot alanı `slot` değil `preferredSlot` ve yalnız saat.
