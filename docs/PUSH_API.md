# Push sözleşmesi

Kaynak: `packages/server/push-devices.ts`, `packages/core/push-notifications.ts`, `packages/server/reminder-push.ts`. Sözleşme Eki v1 ile native iOS (doğrudan APNs) kaydı eklendi; kaynak FCM kaydı değişmedi. Durum: **kod hazır, bağlantısız test geçti; gerçek APNs/FCM'e karşı çalıştırılmadı, gerçek cihazda denenmedi.**

## `/api/me/push`

Sıra kaynakla aynıdır: origin (`mobile,push` modu; native Origin göndermez, portal ve web kabul edilir), sorgu yok, `PUSH_DELIVERY_ENABLED` (kapalıysa 503 `{"error":"Bildirim gönderimi henüz etkin değil."}`), Bearer + doğrulanmış e-posta, hız sınırı, içerik türü (`application/json` tam), gövde.

### GET

200 `{"devices":[{generation,deviceId,platform,locale,enabled,version,updatedAtMs}]}` — en çok 20 cihaz. Jeton, sağlayıcı ve uygulama kimliği yanıtta **yoktur** (kaynak `pushDevice` katı şeması değişmedi; istemciler sağlayıcıyı kendi bilir).

### POST — kayıt (FCM, kaynakla aynı)

```json
{"action":"register","deviceId":"<uuid>","token":"<FCM registration token>","provider":"fcm","platform":"android","locale":"tr","appId":"1:<sender>:android:<hex>"}
```
- `token`: `^[A-Za-z0-9_:.-]{20,4096}$`; `platform`: `web|android|ios`; `appId`: Firebase uygulama kimliği `^1:\d+:(web|android|ios):[a-f0-9]+$`.
- Android native uygulaması FCM SDK'sının ürettiği registration token'ı bu biçimle kaydeder.

### POST — kayıt (APNs, yeni)

```json
{"action":"register","deviceId":"<uuid>","token":"<64+ küçük harf hex>","provider":"apns","platform":"ios","locale":"en","appId":"com.otosanayide.app","environment":"production"}
```
- `token`: APNs cihaz jetonu, küçük harf onaltılık, 32–100 bayt (`^(?:[0-9a-f]{2}){32,100}$`). iOS `didRegisterForRemoteNotificationsWithDeviceToken` verisini küçük harf hex'e çevirir.
- `platform` yalnız `ios`; `appId` bundle id (= APNs `apns-topic`), ters DNS, en çok 155 karakter.
- `environment`: `production` (App Store/TestFlight) veya `sandbox` (Xcode geliştirme derlemesi). Yanlış ortam APNs'te `BadDeviceToken` döner ve cihaz kaydı silinir.

### POST — kaldırma (kaynakla aynı)

```json
{"action":"revoke","deviceId":"<uuid>","expectedVersion":3,"expectedGeneration":"<uuid>"}
```

### Kurallar (ikisi için ortak)

- `appId`, `PUSH_PUBLIC_APP_IDS` virgüllü izin listesinde olmalıdır; değilse 409 `{"error":"Bildirim ayarları değişti. Yenileyip tekrar deneyin."}`. Liste hem Firebase app id'lerini hem iOS bundle id'yi içerebilir.
- Hesap başına 20 cihaz; aynı jeton başka hesap/cihaza bağlıysa 409 (jeton SHA-256 özeti UNIQUE). Kapasite, sürüm ve generation uyuşmazlığı da 409.
- Aynı `deviceId` ile yeniden kayıt `generation`'ı korur, `version`'ı bir artırır; `refreshAfter` 30 gündür (öneri, otomatik iptal değil). Cihaz değiştiren sağlayıcı (fcm↔apns) aynı kayıtta güncellenir.
- Gövde katıdır: fazla alan, eksik `environment` (apns) veya fazladan `environment` (fcm) 400 `{"error":"Bilgileri kontrol edin."}`.

## Gönderim

Uygulama içi kutu push'tan bağımsızdır. Dış gönderim yalnız hatırlatma kuyruğunda (`reminders:deliver-push`, `docs/DEPLOY.md`) yapılır.

- Kapı (port): `App\Domain\Push\PushSender`; sonuç yalnız `accepted` veya `invalid_token`. Diğer her durum `PushUncertain`'dır ve **yeniden gönderilmez** (teslim kaydı `uncertain`, `account_external_operations` defteri aynı kaynağı kilitler; operatör incelemesi gerekir).
- `invalid_token` cihaz kaydını siler. `accepted` terminal teslim değildir: dış işlem defteri kaynakta olduğu gibi sağlayıcı mutabakatı için açık kalır.
- Yük sabittir: başlık `Otosanayide`, gövde TR/EN "Araç bakım veya muayene hatırlatmanı kontrol et.", veri `route=/hesabim`, çakışma anahtarı `otosanayide-reminder`, TTL 24 saat. Araç, plaka, özel başlık, UID yoktur.

| `PUSH_DRIVER` | Davranış |
|---|---|
| `log` (varsayılan) | Sağlayıcıya bağlanmaz, jetonu loglamaz; `APP_ENV=production` iken reddeder (kuyruk sahte "accepted" ile kapanmasın). |
| `live` | Cihazın sağlayıcısına göre `apns` veya `fcm`. |

APNs (`ApnsPushSender`): token tabanlı kimlik, `.p8` (ES256) JWT 50 dakikada bir yenilenir; HTTP/2; `api.push.apple.com` veya `api.sandbox.push.apple.com` cihazın `environment` alanından. `200` → accepted (`apns-id`), `410` veya `400/403` + `BadDeviceToken|Unregistered|DeviceTokenNotForTopic` → invalid_token, diğerleri belirsiz. Ortam: `APNS_KEY_PATH` (sunucudaki .p8 dosyası, 0600), `APNS_KEY_ID`, `APNS_TEAM_ID` (10 karakter).

FCM (`FcmPushSender`): HTTP v1, servis hesabı JSON'undan RS256 JWT → `https://oauth2.googleapis.com/token`; erişim jetonu yalnız süreç belleğinde. `token_uri` yalnız bu sabit adres olabilir. `200 {name}` → accepted, `404 UNREGISTERED` veya kayıt jetonuna işaret eden `400 INVALID_ARGUMENT` → invalid_token, diğerleri belirsiz. Ortam: `FCM_CREDENTIALS_PATH` (sunucudaki JSON, 0600), isteğe bağlı `FCM_PROJECT_ID`.

Bayraklar: `PUSH_DELIVERY_ENABLED` (uç ve gönderim), `REMINDER_PUSH_WORKER_ENABLED` (zamanlayıcı). İkisi de kapalıyken davranış öncekiyle aynıdır.

## Doğrulama durumu

`tests/Unit/A2PushTest.php`: kayıt şeması, ES256/RS256 imzalarının doğrulanması, APNs/FCM istek biçimi ve yanıt eşlemesi `Http::fake` ile. `tests/Feature/A3ReminderWorkersTest.php`: kuyruk, geçersiz jeton silme, belirsiz sonucun yeniden gönderilmemesi (SQLite bellek içi, MySQL kabulü değil). Gerçek APNs/FCM, gerçek cihaz, HTTP/2 bağlantısı ve sağlayıcı hata kodlarının canlı davranışı doğrulanmadı.

Açık (kullanıcı kararı): APNs anahtarı/Team ID/bundle id, Firebase projesi ve servis hesabı (Android FCM), Huawei/HMS desteği yok, web push hedefi.
