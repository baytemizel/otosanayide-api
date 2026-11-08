# otosanayide-api

Otosanayide web ve mobil istemcilerinin kullandığı HTTP API. Laravel 13, PHP 8.3, yalnız MySQL 8.4. Next.js route'larının (`apps/web/src/app/api`, kaynak repo) aynı sözleşmeyle yeniden yazımıdır; karar kayıtları kaynak repoda `docs/decision-log.md` K170 ve sonrası.

Durum: kod hazırlığı. Hiçbir MySQL sunucusunda çalıştırılmadı, canlı kabul yapılmadı.

## Geliştirme

```bash
composer install
```
```bash
composer test
```
```bash
composer analyse
```

`composer test` veritabanına bağlanmaz. Gerçek MySQL gerektiren testler `tests/Mysql` altındadır. Bağlantıyı `.env` dosyasındaki `DB_TEST_*` değerlerinden alır, yalnız `otosanayide_test` üzerinde çalışır ve o veritabanını her çalıştırmada sıfırlar:

```bash
composer test:mysql
```

Sözleşme kontrolü, Pest'in `storage/contract` altına yazdığı yanıtları kaynak repodaki `packages/core` ayrıştırıcılarından geçirir:

```bash
cd scripts/contract && npm ci && npm run check
```

## Belgeler

- [Route paritesi](docs/ROUTE_PARITY.md): her method için durum, controller, test.
- [Şema haritası](docs/SCHEMA_MAP.md), [F5](docs/schema/f5.md) ve [F6](docs/schema/f6.md) tabloları.
- [Giriş uçları](docs/AUTH_API.md), [OpenAPI](docs/openapi.yaml), [Dağıtım](docs/DEPLOY.md).

Üretmek için: `python3 scripts/parity.py`, `python3 scripts/openapi.py`.

## Kurallar

- Dış çıktı yalnız `{"error":"..."}`; yanıtlar `App\Http\Api` ile üretilir.
- Kontrol sırası kaynak handler ile aynıdır: origin, sorgu, içerik türü, jeton, e-posta doğrulaması, hız sınırı, bayrak, gövde.
- Yazımlar `App\Support\Tx` + `lockForUpdate`; transaction içinde dış çağrı yok; belirsiz dış sonuç yeniden gönderilmez.
- Şema yalnız migration ile değişir; uygulama kullanıcısı DML yetkilidir.
- Sır, `.env`, gerçek işletme verisi commit edilmez.
