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
`.env` sunucuda elle oluşturulur, repoya girmez: `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `PUBLIC_WEB_ORIGIN`, `DB_*` (`/etc/otosanayide/mysql.env` değerleri, `DB_PORT=33066`), `MAIL_*`. `APP_KEY` TOTP sırlarını da şifreler: değişirse kayıtlı TOTP sırları okunamaz.

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
Next.js web uygulaması `/`, API `/api` üzerinde aynı origin'de kalır:
```nginx
location ^~ /api/ {
    root /srv/otosanayide-api/public;
    client_max_body_size 12m;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```
`client_max_body_size` uçların en büyük sınırının üstünde tutulur; asıl sınırlar uygulamadadır. Yanıt tamponu kapatılmaz.

Laravel varsayılan olarak proxy'ye güvenmez. nginx `REMOTE_ADDR`'ı php-fpm'e gerçek istemci olarak verdiği için tek sunucuda ek ayar gerekmez; önüne CDN/yük dengeleyici gelirse `trustProxies` yapılandırılmalıdır, aksi halde IP tabanlı hız sınırı tek adrese düşer.

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
`ops:cleanup` saatlik çalışır. `REQUEST_NOTIFICATIONS_WORKER_ENABLED` ve `GARAGE_RECYCLE_PURGE_ENABLED` varsayılan kapalıdır; açmadan önce `--apply` olmadan çıktı incelenir. E-posta/push hatırlatma işçileri taşınmadı (MAIL-01, PUSH-01).

## Depolama
Medya `filesystems.default` diskindedir; uygulama diskin yerel/özel olduğunu ad ve `visibility` ile denetler. Görseller imzalı URL ile değil, kaynaktaki gibi API üzerinden akıtılır (istemciler `redirect:'error'` kullanır). `storage/` web kökünden erişilemez, `www-data` yazabilir. Depolama hedefi (yerel/S3 uyumlu) açık karardır.

## Yayın öncesi açıklar
33066 tüm internete açık, TLS zorunlu değil (K173). `otosanayide_test` üzerinde `tests/Mysql` çalıştırılmadan yayın yapılmamalıdır.
