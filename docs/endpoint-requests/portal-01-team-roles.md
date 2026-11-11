---
platform: portal
status: done
priority: P2
area: core
screen: B08 · /ekip · Ekip ve roller (davet, rol, yetki, durum)
---
## İhtiyaç
Portal ekip ekranı yalnız oturumdaki kişinin kendi rolünü (`GET /api/businesses/manage` içindeki `role`) gösterebiliyor. Şubedeki diğer üyeleri listelemek, çalışan davet etmek, rolü değiştirmek veya üyeliği askıya almak için uç yok; "Kişi davet et" ve liste K183 kuralıyla pasif. B00e'deki "Davet bekliyorum" seçeneği de bu uca bağlı.

## Önerilen sözleşme
Hepsi Bearer, e-postası doğrulanmış hesap, `businessId` ile kapsanır; yalnız o şubede aktif `owner` üyeliği olan yazabilir, `staff` yalnız okur. Örnek işletmeler (`sample=true`) 403.

1. `GET /api/businesses/team?businessId=<id>` → 200
   `{"businessId":"…","members":[{"memberId":"<uuid>","email":"a***@ornek.com","role":"owner|staff","status":"active|suspended|invited","permissions":{"profile":true,"replies":true,"requests":true,"campaigns":false,"team":false},"invitedAtMs":…,"joinedAtMs":…|null}],"version":3}`
   E-posta yalnız sahip için tam, çalışan için maskeli. 403 üye değil · 404 işletme yok.
2. `POST /api/businesses/team/invitations` · `{"businessId","email","role":"staff","permissions":{…},"operationId":"<uuid>"}` → 201 `{"memberId","status":"invited","expiresAtMs"}`. Davet e-postası `<origin>/giris/kayit?davet=<token>` (Sözleşme Eki v1 md. 4 ile aynı origin kuralı). Aynı e-postaya açık davet varsa 409. `owner` daveti bu uçla yapılamaz (sahiplik devri ayrı süreç, product-plan "Sahiplik doğrulama").
3. `POST /api/businesses/team/invitations/accept` · `{"token"}` → 200 `{"businessId","role"}`; davet e-postası ile oturum e-postası eşleşmeli.
4. `PATCH /api/businesses/team` · `{"businessId","memberId","expectedVersion","action":"suspend|resume|remove|permissions","permissions?":{…}}` → 200 `{"version"}`. Son aktif `owner` kaldırılamaz/askıya alınamaz (409). Finans ve ekip yetkisi çalışanlarda varsayılan kapalı.
Hata: 400 gövde · 401 · 403 · 409 sürüm/durum çakışması · 429.

## Kaynak dayanağı
product-plan "Ürün yüzeyleri ve roller" (işletme çalışanı; finans ve yetki yönetimi varsayılan kapalı; aynı kişi birden fazla organizasyonda rol sahibi), "Sahiplik doğrulama ve işletme açılışı" (şube üyeliği: aktif → askıya alındı → iptal/devredildi); Figma K169 B08 ve B00e; K183 pasif "ekip ve roller".

## API yanıtı
API-A 2. tur, 11 Kasım 2026. Kod hazır, bağlantısız/SQLite bellek testleri; canlı değil, MySQL'de çalıştırılmadı.

Muhafazakâr kurallar; rol modeli `business_memberships` (`owner|staff`) aynen, yeni rol veya izin bayrağı YOK.
- `GET /api/businesses/team?businessId=` · aktif üye (owner/staff), doğrulanmış e-posta → `{"businessId","yourRole","members":[{"memberId","email","role","status":"active|suspended","version","you"}],"invitations":[{"invitationId","email","role":"staff","expiresAtMs","createdAtMs"}]}`. E-posta sahibe tam, çalışana maskeli (`a***@alan.com`); davetler yalnız sahibe.
- `POST /api/businesses/team/invitations` `{"businessId","email"}` · yalnız doğrulanmış sahip (aktif owner + sahipliği doğrulanmış, örnek olmayan işletme) → 201 `{"invitationId","email","role":"staff","status":"pending","expiresAtMs"}`. Davet 7 gün, tek kullanımlık; e-posta bağlantısı `<origin>/ekip/davet?token=…` (portal'dan başlatıldıysa portal origin'i). Aynı adrese açık davet veya mevcut üye 409; en çok 20 açık davet; 20/gün.
- `POST /api/businesses/team/invitations/accept` `{"token"}` · doğrulanmış hesap, oturum e-postası davet e-postasıyla birebir aynı → 200 `{"businessId","role":"staff"}`; aksi 400.
- `PATCH /api/businesses/team` · yalnız sahip: `{"businessId","memberId","expectedVersion","action":"suspend|resume|remove"}` veya `{"businessId","invitationId","action":"cancel_invitation"}` → 200 `{"ok":true,"version"?}`. Sahip üyeliği bu uçla değiştirilemez (409).
- Davetle verilen rol yalnız `staff`; sahiplik, finans ve reklam satın alma yetkisi davetle verilmez. Sahiplik devri ve rol değiştirme (staff↔owner) YOK. Her yazım `business_team_audit`'e işlenir.
Önerilenden farklar: `permissions` nesnesi yok (mevcut rol modeli); kabul sayfası `/giris/kayit?davet=` değil `/ekip/davet?token=` (portal bu sayfayı sağlamalı).
