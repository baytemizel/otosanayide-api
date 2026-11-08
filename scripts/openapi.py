#!/usr/bin/env python3
"""docs/openapi.yaml: Laravel route listesinden ve docs/*status* dosyalarindan uretilir (veritabani gerekmez)."""
import json, pathlib, re, subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
routes = json.loads(subprocess.check_output(["php", "artisan", "route:list", "--json", "--except-vendor"], cwd=ROOT))
status = json.loads((ROOT / "docs/route-status.json").read_text())
for extra in sorted((ROOT / "docs/status").glob("*.json")):
    status.update(json.loads(extra.read_text()))
PUBLIC = re.compile(r"^/api/(health|search|businesses/\{id\}|auth/(register|login|mfa/verify|refresh|logout|email/verify|password/(forgot|reset))|ad-events|brand-events|brand-banners.*|payments/webhook|businesses/photos/(media|public)|review-photos/media|reviews)$")
CONTRACT = {"/api/search get": "search", "/api/me get": "account", "/api/me/favorites get": "favorites-page", "/api/me/garage get": "garage-backup", "/api/me/garage/lifecycle get": "garage-recycle", "/api/me/privacy get": "privacy", "/api/admin/privacy get": "privacy-queue", "/api/me/push get": "push", "/api/requests get": "requests", "/api/reviews get": "public-reviews-f5", "/api/reviews/mine get": "own-review", "/api/review-cases get": "review-cases-f5", "/api/review-photos get": "own-review-photos", "/api/businesses/photos get": "business-photos", "/api/businesses/photos/public get": "public-business-photos", "/api/businesses/console get": "console-branches", "/api/businesses/manage get": "managed-profile", "/api/businesses/activation get": "activation", "/api/businesses/places post": "business-places"}
SAMPLES = {}
for f in (ROOT / "storage/contract").glob("*.json"):
    SAMPLES[f.stem] = json.loads(f.read_text())["response"]


def schema(v, indent):
    pad = " " * indent
    if isinstance(v, bool):
        return [f"{pad}type: boolean"]
    if isinstance(v, int):
        return [f"{pad}type: integer"]
    if isinstance(v, float):
        return [f"{pad}type: number"]
    if isinstance(v, str):
        return [f"{pad}type: string"]
    if v is None:
        return [f"{pad}type: ['null', string, number, object]"]
    if isinstance(v, list):
        out = [f"{pad}type: array"]
        if v:
            out += [f"{pad}items:"] + schema(v[0], indent + 2)
        return out
    out = [f"{pad}type: object"]
    if v:
        out.append(f"{pad}properties:")
        for k, x in v.items():
            out.append(f"{pad}  {json.dumps(k, ensure_ascii=False)}:")
            out += schema(x, indent + 4)
    return out


lines = ["openapi: 3.1.0", "info:", "  title: Otosanayide API", "  version: 0.1.0",
         "  description: Route listesinden uretildi. Yanit semalari yalniz sozlesme ornegi olan uclarda vardir ve ornekten cikarilir; istek govdesi kurallari kaynak Zod semalarindadir (app/Support/Z tasimalari). Durum icin docs/ROUTE_PARITY.md.",
         "servers:", "  - url: /", "components:", "  securitySchemes:", "    bearer:", "      type: http", "      scheme: bearer",
         "  schemas:", "    Error:", "      type: object", "      required: [error]", "      properties:", "        error:", "          type: string",
         "  responses:", "    Error:", "      description: Hata govdesi", "      content:", "        application/json:", "          schema:", "            $ref: '#/components/schemas/Error'", "paths:"]
paths = {}
for r in routes:
    uri = "/" + r["uri"]
    for m in r["method"].split("|"):
        if m == "HEAD":
            continue
        paths.setdefault(uri, {})[m.lower()] = r["action"].rsplit("\\", 1)[-1]
for uri in sorted(paths):
    lines.append(f"  {json.dumps(uri)}:")
    for m in sorted(paths[uri]):
        state = status.get(f"{m.upper()} {uri}", ["yeni uc" if uri.startswith(("/api/auth", "/api/businesses/{id}")) else "bekliyor"])[0]
        lines += [f"    {m}:", f"      summary: {json.dumps(paths[uri][m] + ' (' + state + ')', ensure_ascii=False)}"]
        if "{id}" in uri:
            lines += ["      parameters:", "        - {name: id, in: path, required: true, schema: {type: string}}"]
        if m != "options" and not PUBLIC.match(uri):
            lines += ["      security:", "        - bearer: []"]
        if m in ("post", "put", "patch"):
            lines += ["      requestBody:", "        content:", "          application/json:", "            schema:", "              type: object"]
        lines += ["      responses:", "        '200':", "          description: Basarili"]
        sample = SAMPLES.get(CONTRACT.get(f"{uri} {m}", ""))
        if sample is not None:
            lines += ["          content:", "            application/json:", "              schema:"] + schema(sample, 16)
        lines += ["        default:", "          $ref: '#/components/responses/Error'"]
(ROOT / "docs/openapi.yaml").write_text("\n".join(lines) + "\n", encoding="utf8")
print(sum(len(v) for v in paths.values()), "operation")
