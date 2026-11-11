# Kendi giriş uçları

Kaynakta Firebase Auth istemcisi vardı; bu uçlar yenidir. Web (Nuxt), işletme portalı ve native iOS/Android istemcileri aynı uçları kullanır; yalnız yenileme jetonunun taşınma biçimi istemci kanalına göre değişir (Sözleşme Eki v1).

| Uç | Gövde | Yanıt |
|---|---|---|
| `POST /api/auth/register` | `{email,password}` | 202 `{ok:true}` |
| `POST /api/auth/login` | `{email,password}` | 200 oturum veya `{mfaRequired:true,challenge}` |
| `POST /api/auth/mfa/verify` | `{challenge,code}` | 200 oturum |
| `POST /api/auth/refresh` | tarayıcı: çerez · native: `{refreshToken}` | 200 oturum |
| `POST /api/auth/logout` | tarayıcı: çerez · native: `{refreshToken}` | 204 |
| `POST /api/auth/email/verify` | `{token}` | 200 `{ok:true}` |
| `POST /api/auth/email/resend` | Bearer | 202 |
| `POST /api/auth/password/forgot` | `{email}` | 202 |
| `POST /api/auth/password/reset` | `{token,password}` | 200 |
| `POST /api/auth/password/change` | Bearer + `{currentPassword,newPassword,code?}` | 200 oturum (web-02) |
| `GET /api/auth/mfa` | Bearer | 200 `{totp:{enabled,enabledAtMs}}` (web-01) |
| `POST /api/auth/email/change` | Bearer + `{newEmail,password,code?}` | 202 `{ok:true}` (web-03) |
| `POST /api/auth/mfa/totp/enroll` | Bearer | 201 `{secret,otpauthUri}` |
| `POST /api/auth/mfa/totp/confirm` | `{code}` | 200 |
| `POST /api/auth/mfa/totp/disable` | `{code}` | 200 |

## İstemci kanalı

Kanal her istekte başlıklardan belirlenir (`App\Domain\Auth\AuthClient`). Origin denetimi `origin:account` modudur: web origin'i, onun loopback eşdeğeri, `PORTAL_WEB_ORIGIN` ve Origin'siz istek geçer; diğer origin'ler 403 alır.

| Kanal | Koşul | Yenileme jetonu |
|---|---|---|
| web | `Origin` = `PUBLIC_WEB_ORIGIN` | `otosanayide_refresh` çerezi |
| portal | `Origin` = `PORTAL_WEB_ORIGIN` | `otosanayide_portal_refresh` çerezi |
| native | `Origin` yok, `X-Client-Platform: ios\|android`, `X-Client-Version: <semver>` | yanıt gövdesinde `refreshToken`, çerez yok |

- Oturum veren veya tüketen beş uçta (`login`, `mfa/verify`, `refresh`, `logout`, `password/change`) `Origin` yoksa iki başlık zorunludur. Platform yalnız küçük harfle `ios` veya `android`; sürüm `1.2.3`, `1.2.3-beta.1`, `1.2.3+45` biçiminde. Eksik veya geçersizse 400 `{"error":"Geçersiz istek."}`. Bu denetim origin aşamasıdır, içerik türü ve gövdeden önce gelir.
- `Origin` başlığı varsa yanıt gövdesinde `refreshToken` **asla** dönmez; native başlıklar gönderilse bile kanal tarayıcıdır. Tarayıcı `refresh`/`logout` gövdeyi okumaz, yalnız o origin'e ait çerezi okur. Web çerezi portalda, portal çerezi webde geçersizdir.
- Diğer uçlarda (`register`, `password/*`, `email/*`, `totp/*`) kanal başlığı denetlenmez; native istemci yine de her istekte `X-Client-Platform`, `X-Client-Version`, `Accept-Language` gönderir.

### Oturum yanıtı

Tarayıcı (web, portal):
```json
{"accessToken":"…","expiresIn":900,"user":{"uid":"…","email":"…","emailVerified":true}}
```
`Set-Cookie: <kanal çerezi>=…; Path=/api/auth; Secure; HttpOnly; SameSite=Strict`

Native:
```json
{"accessToken":"…","expiresIn":900,"refreshToken":"…","user":{"uid":"…","email":"…","emailVerified":true}}
```
`Set-Cookie` yoktur. Yanıtlar `Cache-Control: private, no-store` taşır.

### Native yenileme ve çıkış

```
POST /api/auth/refresh
Content-Type: application/json
X-Client-Platform: ios
X-Client-Version: 1.0.0

{"refreshToken":"…"}
```
- Gövde katı nesnedir: yalnız `refreshToken` (1–256 karakter). Fazla alan veya eksik alan 400 `{"error":"Bilgileri kontrol edin."}`; `application/json` değilse 415.
- Bilinmeyen, süresi dolmuş veya iptal edilmiş jeton 401 `{"error":"Oturum geçersiz veya süresi dolmuş."}`.
- Her başarılı yenilemede yeni `refreshToken` döner (rotation); eski jeton iptal edilir. İptal edilmiş jetonun yeniden kullanımı tüm oturum ailesini (o girişten türeyen bütün jetonları ve erişim jetonlarını) iptal eder ve 401 döner.
- `logout` aynı gövdeyi alır, aileyi iptal eder ve her durumda 204 döner (bilinmeyen jeton da 204). Gövde geçersizse 400/415.
- Hız sınırı: yenileme IP başına 120/dk (tarayıcıyla ortak).

İstemci kuralları: yenileme tek uçuşludur (aynı anda tek `refresh`); `refreshToken` yalnız Keychain (iOS) veya Keystore destekli şifreli depoda (Android) tutulur; `accessToken` yalnız bellektedir. 401 sonrası tek yenileme denenir; yenileme 401 dönerse oturum kapatılır ve depodaki jeton silinir. Jeton loglanmaz.

### Portal

Portal `/api/auth/*` çağrılarını `credentials: 'include'`, diğer bütün çağrıları `credentials: 'omit'` + `Authorization: Bearer` ile yapar. CORS kimlik bilgisi izni yalnız `/api/auth/*` altındadır. Portal origin'i `/api/admin/*` uçlarında ne CORS ne origin izni alır: yönetim yalnız web `/yonetim` içindedir (bilinçli karar; ayrıntı `docs/CLIENT_ACCESS.md`).

## E-posta bağlantıları

Doğrulama ve sıfırlama bağlantıları isteği başlatan origin'e göre kurulur: `<origin>/giris/eposta-dogrula?token=…`, `<origin>/giris/sifre-sifirla?token=…`. Portal'dan başlatılan `register`, `email/resend`, `password/forgot` portal adresini; web ve native'den başlatılanlar web adresini kullanır. Kuyruktaki origin gönderim anında yeniden denetlenir: yapılandırılmış portal değilse web adresi kullanılır.

## E-posta değişimi (web-03)

Hesap yeni adres onaylanana kadar değişmez. Yeni adrese 24 saat geçerli bağlantı (`<origin>/giris/eposta-dogrula?token=…`), eski adrese bilgilendirme gider; yeni adres başka hesaba aitse yanıt yine 202'dir. Onay `POST /api/auth/email/verify` ile yapılır; başarıda e-posta değişir, doğrulanmış sayılır, bütün oturum aileleri ve erişim jetonları iptal edilir ve eski adrese bildirim gider. Hatalı şifre/kod 400, kod eksikse 403, hesap başına 3/sa.

## Süreler ve sınırlar

Süreler öneridir, kullanıcı kabulü bekler: erişim 15 dk, yenileme 30 gün, doğrulama 24 sa, sıfırlama 1 sa, MFA challenge 5 dk ve 5 deneme. Hız sınırları: giriş e-posta başına 10/15 dk, IP başına 60/15 dk; kayıt IP başına 10/sa; sıfırlama e-posta başına 3/sa; yenileme IP başına 120/dk; şifre değişimi hesap başına 5/sa; MFA durumu hesap başına 60/dk.

Şifre değişimi (web-02): mevcut şifre ve TOTP etkinse `code` ister. Hatalı şifre veya kod 400 `{"error":"Şifre veya doğrulama kodu hatalı."}` (401 değil: istemcinin oturum yenilemesini tetiklememeli), kod eksikse 403 `{"error":"İki adımlı doğrulama kodu gerekli."}`. Başarıda bütün oturum aileleri ve erişim jetonları iptal edilir, çağırana yeni aile verilir ve bilgilendirme e-postası gider. IP, güvenilen proxy tanımına göre çözülür (`TRUSTED_PROXIES`, `docs/DEPLOY.md`).

Argon2id: bellek 64 MiB, zaman 3, paralellik 1. Şifre en az 10 karakter (web formundaki minLength).

Yanıtlar e-postanın varlığını açığa çıkarmaz. Var olan hesaba kayıt denemesinde hesaba bilgilendirme e-postası gider.

Yeniden düzenlendi: 0002'deki `claims` JSON yerine üç boolean kolon; `failed_login_count/locked_until` yerine e-posta anahtarlı hız sınırı (hesaba bağlı kilit varlığı açığa çıkarırdı); `auth_throttle` yerine Laravel RateLimiter (database).

## Doğrulama durumu

Kanal ayrımı, gövde/çerez kuralları, portal CORS ve e-posta bağlantı origin'i bağlantısız Feature testleriyle doğrulandı (`tests/Feature/AuthChannelTest.php`, `WebCorsTest.php`, `SecurityRouteMatrixTest.php`); bu testler `AuthService` yerine aile kurallarını taklit eden sahte servis kullanır. Gerçek `AuthService` rotation/aile iptali yalnız K177'de MySQL'de doğrulanmıştır ve bu değişiklikte mantığı değişmedi. Gerçek cihaz, gerçek tarayıcı ve canlı API ile denenmedi.

Açık: kurtarma kodu yok (TOTP cihazı kaybında operatör süreci yok); telefon MFA için SMS sağlayıcısı yok, yalnız TOTP çalışır; Google/Apple ile giriş yok (sağlayıcı kararı).
