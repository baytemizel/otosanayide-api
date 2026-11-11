---
platform: web
status: done
priority: P2
area: core
screen: /hesabim · Güvenlik · E-posta adresini değiştir
---
## İhtiyaç
Hesap e-postası değiştirilemiyor. Değişim, yeni adresin sahipliği kanıtlanmadan uygulanmamalı.

## Önerilen sözleşme
`POST /api/auth/email/change` · Bearer · JSON
- Gövde `{"newEmail":"…","password":"…"}` (+ TOTP etkinse `code`).
- 202 `{"ok":true}`: hesap değişmez; yeni adrese tek kullanımlık bağlantı gider: `<origin>/giris/eposta-dogrula?token=…` (Sözleşme Eki v1 md. 4, amaç `change_email`). Eski adrese bilgilendirme gider. Yeni adres başka hesaba aitse yanıt yine 202'dir (varlık açığa çıkmaz), yeni adrese "bu adres zaten kayıtlı" bilgisi gider.
- Onay mevcut `POST /api/auth/email/verify {token}` ile yapılır (aynı sayfa, amaç token kaydında). Başarılı onayda e-posta değişir, `emailVerified=true`, diğer bütün yenileme aileleri iptal edilir.
- 400 · 401 (jeton veya şifre hatalı, ayrım yok) · 429 (hesap başına 3/sa).

## Kaynak dayanağı
product-plan "Ekran envanteri → Üyelik (profil, doğrulama)"; AUTH_API.md e-posta bağlantı kuralları.

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

`POST /api/auth/email/change` · Bearer · `Content-Type: application/json` · gövde (katı) `{"newEmail","password","code"?}` (`code` yalnız TOTP etkinse zorunlu).
- 202 `{"ok":true}`. Hesap DEĞİŞMEZ; yeni adrese 24 saat geçerli tek kullanımlık bağlantı gider: `<origin>/giris/eposta-dogrula?token=…` (origin: portaldan başlatıldıysa portal, aksi halde web). Eski adrese "değişiklik istendi" bilgisi gider. Yeni adres başka hesaba aitse yanıt yine 202, yeni adrese "zaten kayıtlı" bilgisi gider. Yeni istek önceki açık değişim bağlantısını geçersiz kılar.
- Onay mevcut `POST /api/auth/email/verify {"token"}` ile (aynı sayfa). Başarıda e-posta değişir, `emailVerified=true`, **bütün** oturum aileleri ve erişim jetonları iptal edilir (istemci yeniden giriş ister), eski adrese "e-posta değiştirildi" bilgisi gider. O anda adres başka hesaba geçmişse 400 `{"error":"Bağlantı geçersiz veya süresi dolmuş."}`.
- Hatalar: 415 · 401 jeton · 400 `{"error":"Bilgileri kontrol edin."}` (gövde veya yeni adres mevcut adresle aynı) · 400 `{"error":"Şifre veya doğrulama kodu hatalı."}` · 403 `{"error":"İki adımlı doğrulama kodu gerekli."}` · 429 (hesap başına 3/sa).
Önerilenden farklar: hatalı şifre 401 değil 400 (web-02 ile aynı gerekçe); token kaydı ayrı tablo (`auth_email_changes`).
