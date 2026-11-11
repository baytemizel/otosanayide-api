---
platform: web
status: done
priority: P2
area: core
screen: S02 kapsam dışı il · "Açılınca haber ver"
---
## İhtiyaç
Kapsam dışı ilde "Açılınca haber ver" pasif. Ürün planı isteğe bağlı açılış bildirimi sunar.

## Önerilen sözleşme
`POST /api/coverage/waitlist` · Bearer (doğrulanmış e-posta) · JSON `{"city":"<81 il listesinden>","operationId":"<uuid>"}` → 201 `{"city":"…","createdAtMs":…}` (aynı il tekrarında 200 aynı kayıt).
`DELETE /api/coverage/waitlist?city=…` · Bearer → 204.
`GET /api/coverage/waitlist` · Bearer → `{"items":[{"city","createdAtMs"}]}`.
- Kapsamdaki il 400. Kullanıcı başına en çok 5 il. Bildirim yalnız uygulama içi/e-posta tercihine göre (web-09) gider; pazarlama iletisi sayılmaz.
- Not: bugün web il seçicisi yalnız kapsamdaki illeri sunduğu için S02 ekranı görünmüyor (K183). Uç kabul edilirse web seçiciye kapsam dışı illeri ayrı grup olarak ekler.

## Kaynak dayanağı
product-plan "Konum ve kapsama durumları" (Kapsam dışı seçimde … isteğe bağlı açılış bildirimi sunulur); K183 S02.

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Yalnız oturumlu ve e-postası doğrulanmış hesap; serbest e-posta toplanmaz; gönderim işi YAZILMADI.
- `GET /api/coverage/waitlist` → `{"items":[{"city","createdAtMs"}]}`.
- `POST /api/coverage/waitlist` `{"city":"<81 il adı, Türkçe yazımla>"}` → 201 `{"city","createdAtMs"}` (aynı il tekrarı 200). İl 81 il listesinde olmalı ve kapsamda (bugün 41 il) OLMAMALI; aksi 400 `{"error":"Bu il için bekleme listesi yok."}`. Kullanıcı başına 5 il (409).
- `DELETE /api/coverage/waitlist?city=…` → 204.
