#!/usr/bin/env python3
"""docs/openapi.yaml: Laravel route listesinden ve docs/*status* dosyalarindan uretilir (veritabani gerekmez)."""
import json, pathlib, re, subprocess

ROOT = pathlib.Path(__file__).resolve().parent.parent
routes = json.loads(subprocess.check_output(["php", "artisan", "route:list", "--json", "--except-vendor"], cwd=ROOT))
status = json.loads((ROOT / "docs/route-status.json").read_text())
for extra in sorted((ROOT / "docs/status").glob("*.json")):
    status.update(json.loads(extra.read_text()))
PUBLIC = re.compile(r"^/api/(health|search|businesses/\{id\}|auth/(register|login|mfa/verify|refresh|logout|email/verify|password/(forgot|reset))|ad-events|brand-events|brand-banners.*|payments/webhook|businesses/photos/(media|public)|review-photos/media|reviews)$")
lines = ["openapi: 3.1.0", "info:", "  title: Otosanayide API", "  version: 0.1.0",
         "  description: Route listesinden uretildi. Govde semalari icin kaynak Zod semalari ve docs/ROUTE_PARITY.md esastir; yanit semalari tasinmadi.",
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
        lines += ["      responses:", "        '200':", "          description: Basarili", "        default:", "          $ref: '#/components/responses/Error'"]
(ROOT / "docs/openapi.yaml").write_text("\n".join(lines) + "\n", encoding="utf8")
print(sum(len(v) for v in paths.values()), "operation")
