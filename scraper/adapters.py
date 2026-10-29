"""Shop adapters. Each adapter takes a shop row (from the web app) and returns a
list of raw items:

    {external_id, title, url, image, price, currency, available, status_text, tags, text}

Supported platforms:
  auto         - detect automatically on the first run (default for new shops)
  shopify      - any Shopify store (uses the public /products.json feed)
  woocommerce  - any WooCommerce store (uses the public Store API)
  jsonld       - any other shop: own search + schema.org product data (universal.py)
  html         - manual fallback, configured with CSS selectors
"""
import json
import re
import threading
import time
from urllib.parse import urljoin, quote_plus

import requests
from bs4 import BeautifulSoup

from classify import search_query, term_matches

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/130.0 Safari/537.36 OPTCG-Preorder-Watcher/1.0"
)
TIMEOUT = 25
PAGE_DELAY = 1.0  # be polite: one request per second per shop

_local = threading.local()  # one HTTP session per worker thread


def session() -> requests.Session:
    s = getattr(_local, "s", None)
    if s is None:
        s = requests.Session()
        s.headers.update({"User-Agent": UA, "Accept-Language": "en,de;q=0.8,nl;q=0.7"})
        _local.s = s
    return s


def _get(url: str, **kw) -> requests.Response:
    last = None
    for attempt in range(3):
        try:
            r = session().get(url, timeout=TIMEOUT, **kw)
            if r.status_code in (429, 503):
                time.sleep(5 * (attempt + 1))
                last = RuntimeError(f"HTTP {r.status_code} for {url}")
                continue
            if r.status_code in (401, 403, 404, 410):  # retrying won't help
                raise RuntimeError(f"HTTP {r.status_code} for {url}")
            r.raise_for_status()
            return r
        except requests.RequestException as e:
            last = e
            time.sleep(2 * (attempt + 1))
    raise RuntimeError(str(last))


def _try_get(url: str, **kw) -> requests.Response | None:
    """Single quick attempt, used for probing. Returns None on any failure."""
    try:
        r = session().get(url, timeout=15, **kw)
        return r if r.status_code == 200 else None
    except requests.RequestException:
        return None


def parse_price(text: str | None) -> float | None:
    """Parses '1.299,00 €', '€ 89,95', '89.99', 'EUR 105,99*' -> float."""
    if text is None:
        return None
    if isinstance(text, (int, float)):
        return float(text)
    m = re.search(r"\d[\d.,\s]*", str(text))
    if not m:
        return None
    s = m.group(0).strip().replace(" ", "").replace(" ", "")
    if "," in s and "." in s:
        if s.rfind(",") > s.rfind("."):  # 1.299,00
            s = s.replace(".", "").replace(",", ".")
        else:  # 1,299.00
            s = s.replace(",", "")
    elif "," in s:
        s = s.replace(",", ".") if len(s.split(",")[-1]) in (1, 2) else s.replace(",", "")
    elif s.count(".") == 1 and len(s.split(".")[-1]) == 3:  # 1.299 (thousands)
        s = s.replace(".", "")
    try:
        return round(float(s), 2)
    except ValueError:
        return None


def _strip_html(html: str | None) -> str:
    if not html:
        return ""
    return BeautifulSoup(html, "html.parser").get_text(" ", strip=True)[:3000]


# ---------------------------------------------------------------- Shopify ---
def shopify(shop: dict) -> list[dict]:
    """config:
        collections: ["one-piece", ...]  optional; otherwise the whole catalogue is scanned
        max_pages:   int (default 30, 250 products per page)
        title_filter: regex (default 'one piece') - only keep matching titles
    """
    cfg = shop.get("config") or {}
    base = shop["base_url"].rstrip("/")
    max_pages = int(cfg.get("max_pages", 30))
    title_filter = re.compile(cfg.get("title_filter", r"one\s*piece|\b(OP|EB|PRB)-?\d"), re.I)
    terms = shop.get("_terms") or []
    sources = [f"{base}/collections/{c}/products.json" for c in cfg.get("collections", [])] or [
        f"{base}/products.json"
    ]

    items, seen = [], set()
    for src in sources:
        for page in range(1, max_pages + 1):
            data = _get(src, params={"limit": 250, "page": page}).json()
            products = data.get("products", [])
            if not products:
                break
            for p in products:
                title = p.get("title", "")
                if p["id"] in seen or not (title_filter.search(title) or term_matches(title, terms)):
                    continue
                seen.add(p["id"])
                variants = p.get("variants") or []
                avail_vars = [v for v in variants if v.get("available")]
                pick = min(avail_vars or variants, key=lambda v: float(v.get("price") or 1e9), default=None)
                tags = p.get("tags") or []
                if isinstance(tags, str):
                    tags = [t.strip() for t in tags.split(",")]
                variant_titles = " ".join(v.get("title", "") for v in variants)
                items.append(
                    {
                        "external_id": str(p["id"]),
                        "title": p.get("title", "").strip(),
                        "url": f"{base}/products/{p['handle']}",
                        "image": (p.get("images") or [{}])[0].get("src"),
                        "price": parse_price(pick.get("price")) if pick else None,
                        "currency": "EUR",
                        "available": bool(avail_vars),
                        "status_text": " ".join(tags) + " " + variant_titles + " " + p.get("product_type", ""),
                        "tags": " ".join(tags),
                        "text": _strip_html(p.get("body_html"))[:800],
                    }
                )
            time.sleep(PAGE_DELAY)
    return items


# ------------------------------------------------------------ WooCommerce ---
def woocommerce(shop: dict) -> list[dict]:
    """config:
        search:    search term (default 'one piece')
        category:  optional category id or slug
        max_pages: int (default 10, 100 products per page)
    """
    cfg = shop.get("config") or {}
    base = shop["base_url"].rstrip("/")
    searches = [cfg.get("search", "one piece")] + [search_query(t) for t in (shop.get("_terms") or [])]
    items, seen = [], set()
    for search in dict.fromkeys(searches):
      params = {"per_page": 100, "search": search}
      if cfg.get("category"):
          params["category"] = cfg["category"]
      for page in range(1, int(cfg.get("max_pages", 10)) + 1):
        params["page"] = page
        r = _get(f"{base}/wp-json/wc/store/v1/products", params=params)
        products = r.json()
        if not products:
            break
        for p in products:
            if p["id"] in seen:
                continue
            seen.add(p["id"])
            prices = p.get("prices") or {}
            minor = int(prices.get("currency_minor_unit", 2))
            raw = prices.get("price")
            price = round(int(raw) / (10**minor), 2) if raw not in (None, "") else None
            stock = (p.get("stock_availability") or {}).get("text", "")
            button = (p.get("add_to_cart") or {}).get("text", "")
            tags = " ".join(t.get("name", "") for t in p.get("tags", []))
            cats = " ".join(c.get("name", "") for c in p.get("categories", []))
            items.append(
                {
                    "external_id": str(p["id"]),
                    "title": BeautifulSoup(p.get("name", ""), "html.parser").get_text().strip(),
                    "url": p.get("permalink"),
                    "image": (p.get("images") or [{}])[0].get("src") if p.get("images") else None,
                    "price": price,
                    "currency": prices.get("currency_code", "EUR"),
                    "available": bool(p.get("is_purchasable") and p.get("is_in_stock")),
                    "status_text": f"{stock} {button} {tags} {cats}",
                    "tags": f"{tags} {cats}",
                    "text": _strip_html(p.get("short_description"))[:800],
                }
            )
        time.sleep(PAGE_DELAY)
        if int(r.headers.get("X-WP-TotalPages", page)) <= page:
            break
    return items


# ------------------------------------------------------------ Generic HTML --
def html(shop: dict) -> list[dict]:
    """For shops without a usable API. config:
        urls:   ["https://shop/list?page={page}", ...]  listing or search pages
                ({page} is optional; {q} is replaced with the url-encoded search term)
        q:      search term (default 'one piece english')
        pages:  pages per url (default 1)
        item:   CSS selector for one product tile          (required)
        title:  CSS selector for the title inside the tile  (required)
        link:   CSS selector for the <a> (default: title selector or first a)
        price:  CSS selector for the price                  (required)
        status: CSS selector for availability/badge text   (optional; else the whole tile text is used)
        assume_available: true  - if no sold-out marker is found treat as purchasable (default true)
    """
    cfg = shop.get("config") or {}
    for key in ("urls", "item", "title", "price"):
        if not cfg.get(key):
            raise ValueError(f"html adapter needs config.{key}")
    q = quote_plus(cfg.get("q", "one piece english"))
    items, seen = [], set()
    for tmpl in cfg["urls"]:
        for page in range(1, int(cfg.get("pages", 1)) + 1):
            url = tmpl.replace("{q}", q).replace("{page}", str(page))
            soup = BeautifulSoup(_get(url).text, "html.parser")
            tiles = soup.select(cfg["item"])
            if not tiles:
                break
            for t in tiles:
                title_el = t.select_one(cfg["title"])
                if not title_el:
                    continue
                link_el = t.select_one(cfg["link"]) if cfg.get("link") else (
                    title_el if title_el.name == "a" else (title_el.find("a") or t.find("a"))
                )
                href = urljoin(url, link_el.get("href")) if link_el and link_el.get("href") else url
                if href in seen:
                    continue
                seen.add(href)
                price_el = t.select_one(cfg["price"])
                status_el = t.select_one(cfg["status"]) if cfg.get("status") else None
                tile_text = t.get_text(" ", strip=True)
                status_text = status_el.get_text(" ", strip=True) if status_el else tile_text
                img = t.find("img")
                from classify import SOLD_OUT  # local import to avoid a cycle

                items.append(
                    {
                        "external_id": href,
                        "title": title_el.get("title") or title_el.get_text(" ", strip=True),
                        "url": href,
                        "image": urljoin(url, img.get("data-src") or img.get("src") or "") if img else None,
                        "price": parse_price(price_el.get_text(" ", strip=True)) if price_el else None,
                        "currency": "EUR",
                        "available": None if not cfg.get("assume_available", True)
                        else not bool(SOLD_OUT.search(status_text)),
                        "status_text": status_text,
                        "tags": "",
                        "text": tile_text[:800],
                    }
                )
            time.sleep(PAGE_DELAY)
    return items


def run(shop: dict) -> list[dict]:
    import universal  # local import: universal imports from this module

    cfg = shop.get("config")
    if isinstance(cfg, str):
        cfg = json.loads(cfg) if cfg.strip() else {}
    shop["config"] = cfg if isinstance(cfg, dict) else {}
    adapters = {"shopify": shopify, "woocommerce": woocommerce, "html": html,
                "jsonld": universal.jsonld, "auto": universal.run_auto}
    fn = adapters.get(shop.get("platform") or "auto")
    if not fn:
        raise ValueError(f"unknown platform '{shop['platform']}'")
    return fn(shop)
