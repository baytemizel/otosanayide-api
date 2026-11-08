# otosanayide-api

Laravel 13 / PHP 8.3 / MySQL. apps/web ve apps/mobile HTTP sözleşmesini koruyan API.

- Kaynak gerçeği: ../../apps/web/src/app/api, ../../packages/server, ../../packages/core. Karar: ../../docs/decision-log.md K170-K172 ve sonrası.
- Dış çıktı yalnız `{"error":"..."}`; yanıtlar `App\Http\Api` ile üretilir. Kurallar handler sırasıyla taşınır.
- `composer test` bağlantısızdır. `--group=mysql` testleri yalnız `otosanayide_test` ile ve kullanıcı erişim verdiğinde çalışır.
- Sır, `.env`, gerçek işletme verisi commit edilmez.
