# Dağıtım

Hedef: `odit-01` (Ubuntu 24.04, PHP 8.3-FPM, nginx, MySQL 8.4, port 33066). Bu belge bir plandır; adımlar sunucuda çalıştırılmadı.

## PHP
Gerekli eklentiler: `pdo_mysql, mbstring, intl, gd (WebP), openssl, sodium`. `password_hash` Argon2id desteğiyle derlenmiş olmalı:
```bash
php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'
```
Argon2id 64 MiB kullanır, eşzamanlı giriş sayısı bellek hesabına katılmalı. GD ile görsel kodlama 24 MP'de istek başına yaklaşık 280 MB ister ve kod `ini_set('memory_limit')` ile sınırı yükseltir: php-fpm havuzunda `ini_set` engellenmemeli (`php_admin_value[memory_limit]` kullanılmamalı). `post_max_size` ve `upload_max_filesize` en büyük medya sınırının üstünde olmalı.

## Kurulum
```bash
composer install --no-dev --optimize-autoloader
```
`.env` sunucuda elle oluşturulur, repoya girmez: `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `PUBLIC_WEB_ORIGIN`, `PORTAL_WEB_ORIGIN`, `TRUSTED_PROXIES`, `DB_*` (`/etc/otosanayide/mysql.env` değerleri, `DB_PORT=33066`), `MAIL_*`. `APP_KEY` TOTP sırlarını da şifreler: değişirse kayıtlı TOTP sırları okunamaz.

## Migration
Uygulama kullanıcısı yalnız DML yetkilidir; şema ayrı kullanıcıyla kurulur. Sırayı koruyun, `config:cache` içine kimlik girmesin:
```bash
php artisan config:clear
```
```bash
DB_MIGRATOR_CNF=/etc/otosanayide/mysql-migrator.cnf php artisan migrate --database=mysql_migrator --force
```
```bash
php artisan config:cache
```
`business_search` için `innodb_ft_enable_stopword=OFF` migration içinde ayarlanır; migrator'ın `SESSION` ayarı yapabilmesi gerekir. `ngram_token_size` 2 kalmalıdır (varsayılan).

## Seeder
`db:seed` tek başına hiçbir şey yazmaz. İlk yönetici:
```bash
SEED_ADMIN_EMAIL=ad@alan.com SEED_ADMIN_PASSWORD='...' php artisan db:seed --class=AdminAccountSeeder --force
```
Ardından girişte TOTP kurulmalıdır; yönetici uçları ikinci faktör ister. Yerel sentetik veri: `--class=LocalFixtureSeeder` (üretimde çalışmaz). Katalog `php artisan catalogue:import <dosya>` ile kuru çalışır; yazım için `--apply --confirm-count=N`, açık onayla.

## nginx
API kendi alan adında çalışır: `https://api.otosanayide.com/api/...` (K180). Web uygulaması ayrı adrestedir ve API'yi CORS ile çağırır:
```nginx
server {
    server_name api.otosanayide.com;
    root /srv/otosanayide-api/public;
    client_max_body_size 12m;
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```
`client_max_body_size` uçların en büyük sınırının üstünde tutulur; asıl sınırlar uygulamadadır. Yanıt tamponu kapatılmaz.

## CORS
`PUBLIC_WEB_ORIGIN`, web uygulamasının tarayıcıda görünen tam adresi olmalıdır: `https://otosanayide.com` gibi, sonunda `/` olmadan ve tek değer. `www` ile `www`'suz adreslerden biri diğerine yönlendirilir. Yalnız bu origin `WebCors` katmanından CORS yanıtı alır; yerel değerde kalırsa gerçek siteden gelen istekler reddedilir. Değişiklikten sonra `php artisan config:cache` çalıştırılır.

`PORTAL_WEB_ORIGIN`, işletme portalının tam adresidir (`https://portal.otosanayide.com` gibi; alan adı kullanıcı kararıdır). Aynı biçim kuralları geçerlidir; https zorunludur, yalnız `localhost`/`127.0.0.1`/`[::1]` için http kabul edilir; geçersiz veya web ile aynı değer yok sayılır. Portal, `/api/admin/*` dışındaki uçlarda web ile aynı CORS ve origin iznini alır; yönetici uçları portala kapalıdır (`docs/CLIENT_ACCESS.md`). Boş bırakılırsa portal tarayıcıdan API'yi çağıramaz.

Kimlik bilgisi izni yalnız `/api/auth/*` altındadır. Web ve portal bu uçları `credentials: 'include'` ile çağırmalıdır; web `otosanayide_refresh`, portal `otosanayide_portal_refresh` çerezini alır, ikisi de `api.otosanayide.com` üzerinde kalır. iOS/Android `Origin` göndermez; yenileme jetonunu gövdede alır (`docs/AUTH_API.md`). Diğer uçlar `Authorization: Bearer` kullanır. `otosanayide-locale` çerezi API'ye gitmez, dil `Accept-Language` başlığından okunur.

Kontrol (beklenen: 204 ve `access-control-allow-origin`):
```bash
curl -i -X OPTIONS -H 'Origin: https://otosanayide.com' -H 'Access-Control-Request-Method: POST' https://api.otosanayide.com/api/auth/login
```

## Güvenilen proxy ve HTTPS (K179)

API Cloudflare arkasındadır. `TRUSTED_PROXIES` boşsa hiçbir proxy'ye güvenilmez ve istemci IP'si `REMOTE_ADDR` olur (önceki davranış); bu durumda IP tabanlı hız sınırları Cloudflare uç adreslerine düşer. Çözüm iki yoldan biridir, ikisi birlikte kullanılmaz:

1. Uygulamada: `TRUSTED_PROXIES` virgülle ayrılmış Cloudflare IPv4/IPv6 CIDR listesi olur (güncel liste Cloudflare'in yayımladığı `ips-v4`/`ips-v6` adresleridir; bu depoya indirilmedi, değerler operatör tarafından girilir). Yalnız bu adreslerden gelen `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Port` okunur; `X-Forwarded-Host` hiç okunmaz. `*` tüm kaynaklara güvenir ve sunucu doğrudan erişilebilirken IP sahteciliğine açıktır, kullanılmamalıdır.
2. nginx'te: `real_ip_header CF-Connecting-IP;` ve her Cloudflare aralığı için `set_real_ip_from`; bu durumda `TRUSTED_PROXIES` boş kalır.

Kaynak sunucuya Cloudflare dışından erişim güvenlik duvarıyla kapatılmalıdır; aksi halde (1) yolunda bile doğrudan gelen istek kendi IP'siyle görünür, sorun değildir, ama (2)'de `set_real_ip_from` yanlış ayarlanırsa sahtecilik mümkündür.

HTTP→HTTPS yönlendirmesi uygulamanın değil sunucunun işidir (K179 bulgu 2: `http://` istekleri 200 dönüyordu). Cloudflare'de "Always Use HTTPS" açılır ve/veya nginx'te 80 portu için:
```nginx
server {
    listen 80;
    server_name api.otosanayide.com;
    return 308 https://api.otosanayide.com$request_uri;
}
```
308 yöntem ve gövdeyi korur; istemciler yine de doğrudan `https://` kullanmalıdır. HSTS başlığı (`Strict-Transport-Security: max-age=31536000`) nginx'te eklenebilir; alt alan adı kapsamı kullanıcı kararıdır. Bu adımlar sunucuda uygulanmadı.

## Kuyruk ve zamanlayıcı
```ini
# /etc/systemd/system/otosanayide-queue.service
[Service]
User=www-data
WorkingDirectory=/srv/otosanayide-api
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
Restart=always
```
```bash
* * * * * cd /srv/otosanayide-api && php artisan schedule:run >> /dev/null 2>&1
```
Zamanlanmış işler `routes/console.php` içinde kayıtlıdır ve yalnız bayrakları açıksa zamanlayıcıya girer. Hepsi `withoutOverlapping` + `onOneServer` (kilit `CACHE_STORE=database`) kullanır; `--apply` olmadan kuru çalışır ve veritabanına yazmaz.

| Komut | Sıklık | Bayrak(lar) | Kaynak işi | Not |
|---|---|---|---|---|
| `ops:cleanup` | saatlik | — | — | her zaman açık |
| `notifications:deliver-requests --apply` | dakikalık | `REQUEST_NOTIFICATIONS_WORKER_ENABLED` | `request_inbox` | sağlayıcı yok, kutu kaydı |
| `garage:purge-recycle --apply` | günlük | `GARAGE_RECYCLE_PURGE_ENABLED` | `garage_recycle` | kalıcı silme |
| `reminders:deliver-inbox --apply` | 5 dk | `REMINDER_INBOX_WORKER_ENABLED` | `reminder_inbox` | sağlayıcı yok; vadesi gelen hatırlatmayı kutuya yazar, e-posta/push kuyruğunu işaretler |
| `reminders:deliver-email --apply` | 5 dk | `REMINDER_EMAIL_WORKER_ENABLED` + `EMAIL_DELIVERY_ENABLED` | `reminder_email` | Laravel mailer; TR/EN şablon; sayfa 25, en çok 10 gönderim |
| `reminders:deliver-push --apply` | 5 dk | `REMINDER_PUSH_WORKER_ENABLED` + `PUSH_DELIVERY_ENABLED` | `reminder_push` | `PUSH_DRIVER=live` + APNs/FCM kimlikleri (`docs/PUSH_API.md`) |

`reminders:*` işleri `scheduled_runs` defterini kullanır: her çalışma bir sayfa işler, cursor sayfa sonucuyla aynı transaction'da kaydedilir. Çalışma hata verirse veya lease (240 sn + 5 sn) aşılırsa kayıt `uncertain` kalır ve sonraki çalışmalar **kendiliğinden başlamaz**; lease dolması devralma izni değildir. İnceleme ve açma:
```bash
php artisan ops:scheduled-recover reminder_push
```
```bash
php artisan ops:scheduled-recover reminder_push --expected-version=N --effects-settled --apply
```
`--effects-settled` operatörün çalışanın durduğunu ve belirsiz dış etkileri (`notification_deliveries.state=uncertain`, `account_external_operations.state=uncertain`) incelediğini beyan eder; bu kayıtlar komutla silinmez ve aynı bildirim/cihaza bir daha gönderim yapılmaz. Yeni olaylar `reminder_inbox` tarafından kuyruğa alınır; eski kutu geçmişi dış kanala oynatılmaz, 7 günden eski kuyruk kaydı gönderilmeden `expired` kapanır.

Açma sırası önerisi: önce migration (`2026_11_11_010001`), sonra kuru çalıştırma çıktısı, sonra `REMINDER_INBOX_WORKER_ENABLED`, en son sağlayıcı bayrakları. Hatırlatma e-postası gerçek e-posta sağlayıcısı seçilmeden (MAIL-01) açılmamalıdır; `MAIL_MAILER=log` iken e-postalar yalnız loga yazılır ama "accepted" sayılır. Gerçek sağlayıcıya karşı hiçbir iş çalıştırılmadı.

## 2. tur notları

- Migration `2026_11_11_020001_create_round_two_core_tables` yalnız ekleyicidir; kod ondan **önce** dağıtılmamalıdır (yeni kolonlara yazan uçlar ve yorum özeti hook'u hata verir).
- Yorum özeti: migration sonrası bir kez kuru, sonra yazarak çalıştırılır:
```bash
php artisan reviews:backfill-summary
```
```bash
php artisan reviews:backfill-summary --apply --limit=500
```
  Çıktıdaki `next` boş değilse `--after=<next>` ile devam edilir. Bayes sabitleri (C=5, m=3,5) öneridir.
- Sponsorlu arama: `AD_SERVING_ENABLED=true` iken `GET /api/search` `sponsored` alanında en çok 3 ödenmiş kampanya döner (organik `items`'a karışmaz); kapalıyken boş dizi.
- Takım daveti e-postası `<origin>/ekip/davet?token=…` bağlantısı içerir; portal bu sayfayı sağlamalıdır.

## Depolama
Medya `filesystems.default` diskindedir; uygulama diskin yerel/özel olduğunu ad ve `visibility` ile denetler. Görseller imzalı URL ile değil, kaynaktaki gibi API üzerinden akıtılır (istemciler `redirect:'error'` kullanır). `storage/` web kökünden erişilemez, `www-data` yazabilir. Depolama hedefi (yerel/S3 uyumlu) açık karardır.

## Yayın öncesi açıklar
33066 tüm internete açık, TLS zorunlu değil (K173). Üretim veritabanına karşı test paketi çalıştırılmaz; `migrate:fresh` kullanılmaz, şema değişiklikleri yalnız `migrate` ile uygulanır.
