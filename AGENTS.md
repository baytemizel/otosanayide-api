# otosanayide-api

Laravel 13 / PHP 8.3 / MySQL. apps/web ve apps/mobile HTTP sözleşmesini koruyan API.

- Kaynak gerçeği: ../../apps/web/src/app/api, ../../packages/server, ../../packages/core. Karar: ../../docs/decision-log.md K170-K172 ve sonrası.
- Dış çıktı yalnız `{"error":"..."}`; yanıtlar `App\Http\Api` ile üretilir. Kurallar handler sırasıyla taşınır.
- `composer test` bağlantısızdır. `composer test:mysql` hedef veritabanını silip yeniden kurar; `auth_accounts` veya `businesses` doluysa reddeder. Dolu veritabanında çalıştırılmaz.
- Sır, `.env`, gerçek işletme verisi commit edilmez.
