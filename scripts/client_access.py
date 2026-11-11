#!/usr/bin/env python3
"""docs/CLIENT_ACCESS.md: route listesi + OriginGuard modlari + kaynak mobil uygulamanin kullandigi uclar (veritabani gerekmez)."""
import json, pathlib, re, subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
BUNDLE = ROOT.parent.parent
routes = json.loads(subprocess.check_output(["php", "artisan", "route:list", "--json", "--except-vendor", "-v"], cwd=ROOT))

# apps/mobile/src -> packages/core istemcileri taranarak cikarildi (Sozlesme Eki v1 erisim matrisi).
MOBILE = {
    "/api/search": "GET", "/api/me/favorites": "GET POST", "/api/me/garage": "GET PUT", "/api/me/garage/lifecycle": "GET POST",
    "/api/me/privacy": "GET POST", "/api/me/push": "GET POST", "/api/requests": "GET POST PATCH", "/api/reviews": "GET POST",
    "/api/reviews/mine": "GET PATCH", "/api/review-cases": "GET POST", "/api/review-photos": "GET POST PATCH",
    "/api/review-photos/media": "GET", "/api/businesses/photos": "GET POST PATCH", "/api/businesses/photos/public": "GET",
    "/api/businesses/photos/media": "GET", "/api/businesses/manage": "GET PATCH", "/api/businesses/replies": "GET POST PATCH",
    "/api/businesses/activation": "GET PATCH", "/api/businesses/console": "GET", "/api/businesses/places": "POST",
    "/api/campaigns": "GET PATCH", "/api/campaigns/finance": "GET POST", "/api/campaigns/owner-metrics": "GET",
    "/api/payments/checkout": "POST",
}


def guard(mw):
    for m in mw:
        hit = re.search(r"OriginGuard:([a-z]+)(?:,([a-z]+))?", m)
        if hit:
            return hit.group(1), hit.group(2) or "cors"
    return None, None


rows = {}
for r in routes:
    uri = "/" + r["uri"]
    if not uri.startswith("/api/"):
        continue
    for method in r["method"].split("|"):
        if method in ("HEAD", "OPTIONS"):
            continue
        mode, cors = guard(r["middleware"])
        admin = uri.startswith("/api/admin")
        web = "evet"
        portal = "hayir (yonetim)" if admin else ("hayir" if mode in ("web", "exact", "present") else "evet")
        native = "evet (oturum ucunda X-Client-* zorunlu)" if uri in ("/api/auth/login", "/api/auth/mfa/verify", "/api/auth/refresh", "/api/auth/logout", "/api/auth/password/change") else ("hayir" if mode == "exact" else "evet")
        used = "evet" if method in MOBILE.get(uri, "").split() else ""
        rows[(uri, method)] = (mode or "yok") + ("" if cors in (None, "cors") else "," + cors), web, portal, native, used

out = [
    "# İstemci erişim matrisi",
    "",
    "Üretim: `python3 scripts/client_access.py` (route listesi ve `OriginGuard` modlarından). Sözleşme Eki v1.",
    "",
    "- **web**: `Origin` = `PUBLIC_WEB_ORIGIN`. `WebCors` CORS yanıtı verir; kimlik bilgisi izni yalnız `/api/auth/*`.",
    "- **portal**: `Origin` = `PORTAL_WEB_ORIGIN`. `mobile` ve `account` modlarında ve origin denetimi olmayan uçlarda web ile eşdeğerdir. `/api/admin/*` altında hiçbir modda origin izni ve CORS almaz (bilinçli karar: yönetim web `/yonetim` içindedir; portal oturumu ele geçirilse bile yönetici yüzeyine tarayıcıdan erişemez). `web` modu (yalnız `/api/admin/privacy`) portala kapalıdır.",
    "- **native**: iOS/Android, `Origin` göndermez. Kaynaktaki `garageOriginAllowed` Origin'siz isteği geçirir; bütün korumalı uçlar yine Bearer jeton ister. Native oturum uçları `X-Client-Platform` ve `X-Client-Version` ister (`docs/AUTH_API.md`).",
    "- **mod**: route üzerindeki `OriginGuard` modu. `yok`: kaynakta origin denetimi yok (ör. `/api/me`, `/api/claims`, çoğu `/api/admin/*`); tarayıcıdan yalnız `WebCors` izin verdiği origin okuyabilir. `exact` ve `present` modları şu an hiçbir route'ta kullanılmıyor; kullanılırsa yalnız web origin'ini kabul eder ve native/portal için ayrıca değerlendirilmelidir.",
    "- **mobil kaynak**: `apps/mobile/src` bugün bu ucu çağırıyor (packages/core istemcileri üzerinden). Hepsi native için erişilebilir; çelişki bulunmadı.",
    "- Yönetici uçları native'den teknik olarak çağrılabilir (kaynakta origin denetimi yok, yetki jetondaki rol ve ikinci faktördür); native uygulamalarda yönetim ekranı yoktur.",
    "",
    "| Uç | Method | Mod | Web | Portal | Native | Mobil kaynak |",
    "|---|---|---|---|---|---|---|",
]
for (uri, method), (mode, web, portal, native, used) in sorted(rows.items()):
    out.append(f"| `{uri}` | {method} | {mode} | {web} | {portal} | {native} | {used} |")
(ROOT / "docs/CLIENT_ACCESS.md").write_text("\n".join(out) + "\n", encoding="utf8")
print(len(rows), "satir")
