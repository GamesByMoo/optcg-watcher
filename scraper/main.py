"""OPTCG preorder watcher - scraper entry point.

Normal run (GitHub Actions):
    OPT_API_URL=https://example.de/optcg OPT_API_TOKEN=... python scraper/main.py

Local testing:
    python scraper/main.py --shops-file tests/shops.local.json --dry-run
    python scraper/main.py --only-shop 3 --dry-run          # test one shop from the web app
    python scraper/main.py --url https://shop.example.nl --dry-run   # try any shop URL
"""
import argparse
import json
import os
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

import requests

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import adapters  # noqa: E402
from classify import classify  # noqa: E402

WORKERS = int(os.environ.get("OPT_WORKERS", "15"))       # shops checked at the same time
TIME_BUDGET = int(os.environ.get("OPT_TIME_BUDGET", "1800"))  # seconds; stay well below 45 min


def api(method: str, path: str, **kw):
    base = os.environ["OPT_API_URL"].rstrip("/")
    headers = {"Authorization": f"Bearer {os.environ['OPT_API_TOKEN']}"}
    r = requests.request(method, f"{base}/api/{path}", headers=headers, timeout=120, **kw)
    if r.status_code >= 400:
        raise RuntimeError(f"API {path}: HTTP {r.status_code}: {r.text[:300]}")
    try:
        return r.json()
    except ValueError:
        raise RuntimeError(
            f"API {path}: expected JSON but got HTTP {r.status_code}, "
            f"type {r.headers.get('Content-Type')!r}, final URL {r.url}, body: {r.text[:500]!r}"
        ) from None


def scrape_shop(shop: dict, wanted_types: set, deadline: float, terms: list) -> dict:
    t0 = time.time()
    entry = {"shop_id": shop["id"], "ok": True, "error": None, "items": [], "raw_count": 0}
    if t0 > deadline:
        entry.update(ok=False, skipped=True, error="Skipped: run time budget used up (will be checked next run)")
        return entry
    shop["_terms"] = terms
    try:
        raw = adapters.run(shop)
        entry["raw_count"] = len(raw)
        assume_en = bool((shop.get("config") or {}).get("assume_english", False))
        seen = set()
        for item in raw:
            c = classify(item, assume_english=assume_en, terms=terms)
            if c and c["product_type"] in wanted_types and c["external_id"] not in seen:
                seen.add(c["external_id"])
                for k in ("text", "tags", "status_text", "available"):
                    c.pop(k, None)
                entry["items"].append(c)
    except Exception as e:  # one broken shop must not stop the others
        entry["ok"] = False
        entry["error"] = f"{type(e).__name__}: {e}"[:500]
    if shop.get("_detected"):
        entry["detected"] = shop["_detected"]
    entry["seconds"] = round(time.time() - t0, 1)
    return entry


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--shops-file", help="read shops from a local JSON file instead of the API")
    ap.add_argument("--only-shop", type=int, help="only scrape the shop with this id")
    ap.add_argument("--url", help="try a single shop URL (auto-detect), implies --dry-run")
    ap.add_argument("--terms", help="comma-separated search terms (overrides the web app's list)")
    ap.add_argument("--dry-run", action="store_true", help="print results, do not send them to the API")
    args = ap.parse_args()

    started = datetime.now(timezone.utc)
    if args.url:
        cfg = {"shops": [{"id": 0, "name": args.url, "country": "?", "platform": "auto",
                          "base_url": args.url, "config": {}}]}
        args.dry_run = True
    elif args.shops_file:
        with open(args.shops_file, encoding="utf-8") as f:
            cfg = json.load(f)
    else:
        cfg = api("GET", "shops.php")

    shops = cfg["shops"]
    if args.only_shop:
        shops = [s for s in shops if int(s["id"]) == args.only_shop]
    wanted = set(cfg.get("product_types") or ["display", "case", "special"])
    terms = [t.strip() for t in (cfg.get("search_terms") or []) if t.strip()]
    if args.terms:
        terms = [t.strip() for t in args.terms.split(",") if t.strip()]
    deadline = time.time() + TIME_BUDGET
    print(f"Checking {len(shops)} shops with {WORKERS} workers; search terms: {', '.join(terms) or '-'}")

    names = {s["id"]: s["name"] for s in shops}
    results = []
    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        futures = [pool.submit(scrape_shop, s, wanted, deadline, terms) for s in shops]
        for fut in as_completed(futures):
            r = fut.result()
            results.append(r)
            det = f"  [detected: {r['detected']['platform']}]" if r.get("detected") else ""
            print(f"[{names[r['shop_id']]}] {'OK ' if r['ok'] else 'ERR'} {r['raw_count']} scanned -> "
                  f"{len(r['items'])} relevant ({r['seconds']}s){det}" + (f"  {r['error']}" if r["error"] else ""))

    ok = sum(1 for r in results if r["ok"])
    print(f"Done: {ok}/{len(results)} shops ok, {sum(len(r['items']) for r in results)} offers")

    if args.dry_run:
        for r in results:
            if r.get("detected"):
                print(f"\n{names[r['shop_id']]} config: {json.dumps(r['detected'])}")
            for i in sorted(r["items"], key=lambda x: (x["status"], x["price"] or 0)):
                print(f"  {i['status']:9} {i['product_type']:8} {i['price'] or '?':>8} € "
                      f"{i['set_code'] or '':6} {i['title'][:70]}")
        return 0

    payload = {
        "started_at": started.isoformat(),
        "finished_at": datetime.now(timezone.utc).isoformat(),
        "shops": results,
    }
    resp = api("POST", "ingest.php", json=payload)
    print(f"Ingest: {json.dumps(resp)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
