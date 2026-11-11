---
platform: web
status: rejected
priority: P2
area: core
screen: W01 ana sayfa · "Doğru servisi seçme rehberleri"; alt bilgi "Seçim rehberleri"
---
## İhtiyaç
Rehber kartları başlık/özetle duruyor, "Oku" bağlantısı pasif; rehber metni ve yayın süreci yok. Rehberler editoryal içeriktir ve teknik/garanti iddiası taşımamalı (product-plan: kesin teknik teşhis veya güvenlik garantisi verilmez).

## Önerilen sözleşme
`GET /api/guides` · yetkisiz · `Accept-Language` → 200 `{"items":[{"slug","title","summary","updatedAtMs"}]}` (yalnız yayımlanmış, en çok 20).
`GET /api/guides/{slug}` → 200 `{"slug","title","summary","bodyMarkdown","updatedAtMs","reviewedBy":"editorial"}` · 404.
- `bodyMarkdown` sınırlı alt küme (başlık, liste, bağlantı yalnız aynı site); istemci HTML'e çevirirken ham HTML kabul etmez.
- `Cache-Control: public, max-age=300`. Yazma tarafı yönetim kararına kalır.
- Alternatif (ret halinde): rehberler web deposunda statik içerik olarak, yayın onayı sonrası eklenir; bu durumda uç gerekmez — kararı API/ürün sahibi versin.

## Kaynak dayanağı
product-plan "Ana sayfa" (değerlendirme ve seçim rehberleri), "Ekran envanteri → Sistem ve bilgi (yardım)"; K183 pasifler (rehber kartları).

## API yanıtı
API-A, 11 Kasım 2026. Reddedildi (bu aşama için).

Gerekçe: Rehberler editoryal içeriktir; API'de yazım/onay/yayın akışı, sorumlu editör ve hukuk incelemesi (teknik/garanti iddiası yasağı) tanımlı değil. Salt okunur bir uç, içeriği yine birinin depoya veya veritabanına elle koymasını gerektirir ve veritabanında yönetim yüzeyi olmadan içerik sürümü izlenemez.
İstemcinin yapması gereken: talepteki alternatif — rehberleri onaydan sonra web deposunda statik içerik (Nuxt içerik dosyası) olarak yayımlayın; kartlar onaylı içerik yoksa pasif kalır. Yönetimden düzenlenebilir rehber istenirse ürün sahibi kararıyla yeni talep açılır.
