#!/usr/bin/env python3
"""docs/ROUTE_PARITY.md uretir: kaynak route.ts envanteri + docs/route-status.json."""
import json, pathlib, re

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT.parent.parent / "apps/web/src/app/api"
status = json.loads((ROOT / "docs/route-status.json").read_text())
for extra in sorted((ROOT / "docs/status").glob("*.json")):
    status.update(json.loads(extra.read_text()))
METHODS = "GET POST PUT PATCH DELETE OPTIONS".split()
rows = []
for f in sorted(SOURCE.rglob("route.ts")):
    text = f.read_text(encoding="utf8")
    path = "/api/" + str(f.parent.relative_to(SOURCE))
    found = [m for m in METHODS if re.search(rf"export\s+(async\s+)?(function|const)\s+{m}\b", text) or re.search(rf"export\s*\{{[^}}]*\b{m}\b", text)]
    for m in found:
        rows.append((path, m, *status.get(f"{m} {path}", ["bekliyor", "-", "-"])))
counts = {}
for r in rows:
    counts[r[2]] = counts.get(r[2], 0) + 1
summary = ", ".join(f"{k}: {v}" for k, v in sorted(counts.items()))
out = ["# Route paritesi", "", f"Kaynak: apps/web/src/app/api. {len(rows)} method. {summary}.", "",
       "`tamam`: kod yazildi ve testi var; bu sunucu/MySQL kabulu degildir, bkz. rapor durumu. `kapali bayrak`: kaynaktaki kapali yanit. `bekliyor`: yazilmadi.", "",
       "| Route | Method | Durum | Controller | Test |", "|---|---|---|---|---|"]
for r in rows:
    out.append("| `{}` | {} | {} | {} | {} |".format(*r))
(ROOT / "docs/ROUTE_PARITY.md").write_text("\n".join(out) + "\n", encoding="utf8")
print(len(rows), summary)
