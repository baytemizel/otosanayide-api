---
platform: web
status: done
priority: P1
area: core
screen: /hesabim · Güvenlik · İki adımlı doğrulama
---
## İhtiyaç
Hesap ekranı TOTP'nin etkin olup olmadığını bilmeden "Kur" ya da "Kapat" seçeneğini doğru gösteremiyor. Bugün web, `POST /api/auth/mfa/totp/enroll` yanıtı 409 olursa "zaten etkin" sonucuna düşüyor; bu, durumu öğrenmek için yazma ucu çağırmak demek ve gereksiz bir sır üretme denemesi.

## Önerilen sözleşme
`GET /api/auth/mfa` · Bearer (yalnız oturum sahibi) · gövde yok
- 200 `{"totp":{"enabled":true,"enabledAtMs":1731300000000}}` · etkin değilse `{"totp":{"enabled":false,"enabledAtMs":null}}`
- 401 geçersiz/eksik jeton · 429 hız sınırı
- `Cache-Control: no-store`. Tarayıcıdan çağrı `credentials:'omit'` + Bearer'dır; `/api/auth/*` altında olduğu için web istemcisi bu yolda `credentials:'include'` gönderir, CORS buna izin vermeli (diğer `/api/auth/*` yollarıyla aynı kural).
- Alternatif (kabul): oturum yanıtındaki `user` nesnesine `mfa:{totp:boolean}` eklenmesi. Web her iki biçimi de okuyabilir; GET ucu tercih edilir çünkü giriş yanıtını büyütmez.

## Kaynak dayanağı
docs/AUTH_API.md (TOTP uçları), K182 "TOTP kayıt ekranı eklenmedi", product-plan "Güvenlik ve veri koruması" (admin ve kritik işletme rollerinde MFA).

## API yanıtı
API-A, 11 Kasım 2026. Uygulandı (kod hazır, yerel test geçti; canlı değil).

`GET /api/auth/mfa` · Bearer · gövde yok
- 200 `{"totp":{"enabled":true,"enabledAtMs":1794312000000}}` · etkin değilse `{"totp":{"enabled":false,"enabledAtMs":null}}`. `enabledAtMs` TOTP'nin doğrulandığı an.
- 401 `{"error":"Giriş gerekli."}` / `{"error":"Oturum geçersiz veya süresi dolmuş."}` · 429 (hesap başına 60/dk).
- `Cache-Control: private, no-store`. `/api/auth/*` altında olduğu için CORS kimlik bilgisi izni (`credentials:'include'`) web ve portal için diğer auth uçlarıyla aynıdır; istek çerez gerektirmez.

Önerilenden fark: yok. Oturum yanıtına `mfa` alanı eklenmedi (alternatif seçilmedi).
Test: `tests/Feature/A5AuthSqliteTest.php` (gerçek AuthService, SQLite bellek içi; MySQL kabulü değil).
