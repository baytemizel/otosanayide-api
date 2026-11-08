# Kendi giriş uçları

Kaynakta Firebase Auth istemcisi vardı; bu uçlar yenidir ve `apps/web` geçişi tarafından kullanılacaktır. Mobil Firebase Auth'ta kalır.

| Uç | Gövde | Yanıt |
|---|---|---|
| `POST /api/auth/register` | `{email,password}` | 202 `{ok:true}` |
| `POST /api/auth/login` | `{email,password}` | 200 oturum veya `{mfaRequired:true,challenge}` |
| `POST /api/auth/mfa/verify` | `{challenge,code}` | 200 oturum |
| `POST /api/auth/refresh` | çerez | 200 oturum |
| `POST /api/auth/logout` | çerez | 204 |
| `POST /api/auth/email/verify` | `{token}` | 200 `{ok:true}` |
| `POST /api/auth/email/resend` | Bearer | 202 |
| `POST /api/auth/password/forgot` | `{email}` | 202 |
| `POST /api/auth/password/reset` | `{token,password}` | 200 |
| `POST /api/auth/mfa/totp/enroll` | Bearer | 201 `{secret,otpauthUri}` |
| `POST /api/auth/mfa/totp/confirm` | `{code}` | 200 |
| `POST /api/auth/mfa/totp/disable` | `{code}` | 200 |

Oturum: `{accessToken,expiresIn,user:{uid,email,emailVerified}}` ve `otosanayide_refresh` çerezi (HttpOnly, Secure, SameSite=Strict, Path=/api/auth).

Süreler öneridir, kullanıcı kabulü bekler: erişim 15 dk, yenileme 30 gün, doğrulama 24 sa, sıfırlama 1 sa, MFA challenge 5 dk ve 5 deneme. Hız sınırları: giriş e-posta başına 10/15 dk, IP başına 60/15 dk; kayıt IP başına 10/sa; sıfırlama e-posta başına 3/sa.

Argon2id: bellek 64 MiB, zaman 3, paralellik 1. Şifre en az 10 karakter (web formundaki minLength).

Yanıtlar e-postanın varlığını açığa çıkarmaz. Var olan hesaba kayıt denemesinde hesaba bilgilendirme e-postası gider.

Yeniden düzenlendi: 0002'deki `claims` JSON yerine üç boolean kolon; `failed_login_count/locked_until` yerine e-posta anahtarlı hız sınırı (hesaba bağlı kilit varlığı açığa çıkarırdı); `auth_throttle` yerine Laravel RateLimiter (database).

Açık: kurtarma kodu yok (TOTP cihazı kaybında operatör süreci yok); telefon MFA için SMS sağlayıcısı yok, yalnız TOTP çalışır; web e-posta bağlantı yolları (`/giris/eposta-dogrula`, `/giris/sifre-sifirla`) web geçişinde eklenmelidir.
