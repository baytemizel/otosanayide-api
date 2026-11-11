---
platform: web
status: done
priority: P1
area: core
screen: /hesabim · Güvenlik · Şifre değiştir
---
## İhtiyaç
Oturum açmış kullanıcı mevcut şifresini bilerek yeni şifre belirleyemiyor; yalnız e-postayla sıfırlama var. Ürün planı "Üyelik" grubunda hesap kurtarma ve profil ekranlarını ister; şifre değişimi bunun parçasıdır.

## Önerilen sözleşme
`POST /api/auth/password/change` · Bearer · `Content-Type: application/json`
- Gövde `{"currentPassword":"…","newPassword":"…"}` (yeni şifre kuralları `register` ile aynı: 10–1024 karakter).
- 200: oturum yanıtı (`{accessToken,expiresIn,user}`), tarayıcıda yeni `otosanayide_refresh` (portal origin'inde `otosanayide_portal_refresh`) çerezi; native istekte gövdede `refreshToken` (Sözleşme Eki v1 md. 2). Bu oturum ailesi dışındaki bütün yenileme aileleri iptal edilir, `tokens_valid_after` güncellenir.
- TOTP etkinse gövdeye `code` (6 hane) zorunlu; yoksa 403 `{"error":"…","mfaRequired":true}`.
- 400 gövde/kural hatası · 401 jeton veya mevcut şifre hatalı (yanıt metni ikisini ayırmaz) · 429 hız sınırı (hesap başına 5/sa önerisi) · 415 içerik türü.
- Hesaba "şifren değişti" bilgilendirme e-postası gider.

## Kaynak dayanağı
product-plan "Ekran envanteri → Üyelik", "Güvenlik ve veri koruması"; docs/AUTH_API.md (Argon2id, şifre kuralı).

## API yanıtı
API-A, 11 Kasım 2026. Uygulandı (kod hazır, yerel test geçti; canlı değil).

`POST /api/auth/password/change` · Bearer · `Content-Type: application/json`
- Gövde (katı) `{"currentPassword":"…","newPassword":"…","code":"123456"}`; `code` yalnız TOTP etkinse gerekir; TOTP etkin değilse gönderilen kod yok sayılır. `newPassword` 10–1024.
- 200 oturum yanıtı, `login` ile aynı kanal kuralı (`docs/AUTH_API.md`): web `otosanayide_refresh`, portal `otosanayide_portal_refresh` çerezi; native (Origin yok, `X-Client-Platform`/`X-Client-Version` zorunlu) gövdede `refreshToken`.
- Başarıda hesabın **bütün** oturum aileleri ve erişim jetonları (çağıran dahil) iptal edilir, `tokens_valid_after` güncellenir, açık şifre sıfırlama bağlantıları geçersiz olur ve çağırana yeni bir aile verilir. İstemci yanıttaki yeni `accessToken`'a geçmeli; eski jeton 401 alır. Hesaba "Şifren değiştirildi" bilgilendirme e-postası gider (dil `Accept-Language`).
- Hatalar: 400 `{"error":"Geçersiz istek."}` (Origin yok ve native başlıklar eksik — jetondan önce) · 415 · 401 jeton · 400 `{"error":"Bilgileri kontrol edin."}` gövde/kural · 400 `{"error":"Şifre veya doğrulama kodu hatalı."}` mevcut şifre veya TOTP kodu hatalı (ayrım yok) · 403 `{"error":"İki adımlı doğrulama kodu gerekli."}` TOTP etkin ve `code` yok · 429 (hesap başına 5/sa).

Önerilenden farklar:
1. Hatalı mevcut şifre **401 değil 400**: 401 web istemcisinin oturum yenileme akışını tetiklerdi; mesaj şifre ile kodu ayırmaz.
2. TOTP eksikse gövde yalnız `{"error":"…"}` (API kuralı: hata gövdesine başka alan eklenmez). İstemci 403 + bu durumda kod alanını gösterir.
3. "Bu aile dışındakiler" yerine tüm aileler iptal edilip yeni aile verilir; istemci açısından sonuç aynıdır.
Test: `tests/Feature/A5AuthSqliteTest.php`.
