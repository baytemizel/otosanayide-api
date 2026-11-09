# otosanayide-api

Laravel 13 / PHP 8.3 / MySQL. apps/web ve apps/mobile HTTP sözleşmesini koruyan API.

- Kaynak gerçeği: ../../apps/web/src/app/api, ../../packages/server, ../../packages/core. Karar: ../../docs/decision-log.md K170-K172 ve sonrası.
- Dış çıktı yalnız `{"error":"..."}`; yanıtlar `App\Http\Api` ile üretilir. Kurallar handler sırasıyla taşınır.
- `composer test` bağlantısızdır. `.env` üretim veritabanını gösterir: `migrate:fresh`, `db:wipe` ve test amaçlı yazım yapılmaz.
- Sır, `.env`, gerçek işletme verisi commit edilmez.
