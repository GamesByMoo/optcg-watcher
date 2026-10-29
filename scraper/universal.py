"""Universal shop support.

1. detect(shop)  - figures out how to read a shop from just its URL:
                   Shopify feed -> WooCommerce Store API -> 'jsonld' (any other shop).
2. jsonld(shop)  - works for almost any shop system (Shopware, Magento, PrestaShop,
                   JTL, Lightspeed, WooCommerce themes, custom shops): it runs the shop's
                   own search, follows the product links that look relevant and reads the
                   schema.org product data that shops embed for Google
                   (JSON-LD, with microdata / OpenGraph as fallback). schema.org even has
                   a dedicated 'PreOrder' availability, which many shops use.

jsonld config (all optional - filled in automatically on the first run):
    search_url:    "https://shop.tld/search?search={q}"
    listing_urls:  ["https://shop.tld/one-piece-englisch"]   category pages to scan as well
    queries:       ["one piece display", ...]
    max_products:  80    product pages read per run
Your search terms from the web app are searched in addition to 'queries'.
"""
import json
import re
import time
from urllib.parse import urljoin, urlparse, quote_plus

from bs4 import BeautifulSoup

from adapters import _get, _try_get, parse_price, PAGE_DELAY, shopify, woocommerce
from classify import ONE_PIECE, ACCESSORY, OTHER_LANG, DISPLAY, CASE, SPECIAL, SET_CODE, search_query, term_matches

DEFAULT_QUERIES = ["one piece display", "one piece booster box", "one piece english", "one piece premium"]

# Search URL patterns of common shop systems, tried in this order.
SEARCH_PATTERNS = [
    "/search?search={q}",                 # Shopware 6
    "/search?sSearch={q}",                # Shopware 5
    "/catalogsearch/result/?q={q}",       # Magento
    "/search?controller=search&s={q}",    # PrestaShop
    "/?qs={q}",                           # JTL-Shop 5
    "/navi.php?qs={q}",                   # JTL-Shop 4
    "/search/{q}/",                       # Lightspeed eCom (very common in NL)
    "/?s={q}&post_type=product",          # WooCommerce themes
    "/search?q={q}",                      # Shopify themes, Gambio, many custom shops
    "/suche?q={q}",
    "/zoeken?q={q}",
    "/index.php?route=product/search&search={q}",  # OpenCart
]

AVAILABLE = {"instock", "limitedavailability", "onlineonly", "instoreonly", "preorder", "presale", "backorder"}
UNAVAILABLE = {"outofstock", "soldout", "discontinued"}
STATUS_HINT = re.compile(r"deliver|liefer|availab|verf(ü|ue)gbar|stock|lager|voorraad|levertijd|release|preorder|"
                         r"pre-order|vorbestell|voorverkoop|erschein|buy|cart|warenkorb|winkelwagen", re.I)


# ----------------------------------------------------------------- helpers
def _same_site(url: str, base: str) -> bool:
    a, b = urlparse(url).netloc.lower(), urlparse(base).netloc.lower()
    return a.removeprefix("www.") == b.removeprefix("www.")


def _slug_text(href: str) -> str:
    return re.sub(r"[-_/+.%]", " ", urlparse(href).path)


def _relevant_link(text: str, href: str, terms=()) -> bool:
    """Quick pre-filter on a link before spending a request on the product page."""
    t = f"{text} {_slug_text(href)}"
    if ACCESSORY.search(t) or OTHER_LANG.search(text):
        return False
    if term_matches(t, terms):
        return True
    if not (ONE_PIECE.search(t) or SET_CODE.search(t)):
        return False
    return bool(DISPLAY.search(t) or CASE.search(t) or SPECIAL.search(t) or SET_CODE.search(t))


def _product_links(html: str, page_url: str, base: str, terms=()) -> list[str]:
    """Relevant product links on a page; links matching a search term come first."""
    soup = BeautifulSoup(html, "html.parser")
    out, seen = [], set()
    for a in soup.find_all("a", href=True):
        href = urljoin(page_url, a["href"]).split("#")[0]
        if not href.startswith("http") or not _same_site(href, base) or href in seen:
            continue
        text = " ".join(filter(None, [a.get_text(" ", strip=True), a.get("title", ""), a.get("aria-label", "")]))
        img = a.find("img")
        if img is not None:
            text += " " + (img.get("alt") or "")
        if _relevant_link(text, href, terms):
            seen.add(href)
            out.append((0 if term_matches(f"{text} {_slug_text(href)}", terms) else 1, href))
    return [h for _, h in sorted(out, key=lambda x: x[0])]


# ------------------------------------------------------------- detection
def detect(shop: dict) -> tuple[str, dict]:
    """Returns (platform, config) for a shop given only its base URL."""
    base = shop["base_url"].rstrip("/")
    if _try_get(base + "/") is None:
        raise RuntimeError("Shop website not reachable from GitHub (down, wrong URL, or it blocks cloud servers).")

    r = _try_get(f"{base}/products.json", params={"limit": 1})
    if r is not None:
        try:
            if isinstance(r.json().get("products"), list):
                return "shopify", {}
        except (ValueError, AttributeError):
            pass

    r = _try_get(f"{base}/wp-json/wc/store/v1/products", params={"per_page": 1})
    if r is not None:
        try:
            if isinstance(r.json(), list):
                return "woocommerce", {}
        except ValueError:
            pass

    cfg = find_listing(base)
    if cfg:
        return "jsonld", cfg
    raise RuntimeError("Could not detect a way to read this shop (no product feed, search or One Piece category found). "
                       "Pause it or configure it manually.")


def find_listing(base: str) -> dict:
    """Finds the shop's search URL (preferred) or One Piece category pages."""
    best, best_count = None, 0
    q = quote_plus("one piece display")
    for pattern in SEARCH_PATTERNS:
        url = base + pattern.replace("{q}", q)
        r = _try_get(url)
        time.sleep(PAGE_DELAY / 2)
        landed = urlparse(r.url) if r is not None else None
        if r is None or (landed.path.rstrip("/") in ("", urlparse(base).path.rstrip("/")) and not landed.query):
            continue  # failed or redirected to the homepage
        n = len(_product_links(r.text, r.url, base))
        if n > best_count:
            best, best_count = base + pattern, n
        if n >= 5:
            break
    if best:
        return {"search_url": best}

    # No usable search: look for One Piece category links on the homepage.
    r = _try_get(base + "/")
    if r is None:
        return {}
    soup = BeautifulSoup(r.text, "html.parser")
    cats = []
    for a in soup.find_all("a", href=True):
        href = urljoin(r.url, a["href"]).split("#")[0]
        t = f"{a.get_text(' ', strip=True)} {_slug_text(href)}"
        if _same_site(href, base) and ONE_PIECE.search(t) and not OTHER_LANG.search(t) and href not in cats:
            cats.append(href)
    return {"listing_urls": cats[:6]} if cats else {}


# ------------------------------------------------------- product parsing
def _walk(node):
    if isinstance(node, dict):
        yield node
        for v in node.values():
            yield from _walk(v)
    elif isinstance(node, list):
        for v in node:
            yield from _walk(v)


def _types(node: dict) -> set[str]:
    t = node.get("@type", [])
    return {x.lower() for x in (t if isinstance(t, list) else [t]) if isinstance(x, str)}


def _first_url(v) -> str | None:
    if isinstance(v, str):
        return v
    if isinstance(v, list) and v:
        return _first_url(v[0])
    if isinstance(v, dict):
        return v.get("url") or v.get("contentUrl")
    return None


def _offers(prod: dict) -> list[dict]:
    found = []
    for source in [prod] + [v for v in (prod.get("hasVariant") or []) if isinstance(v, dict)]:
        o = source.get("offers")
        for x in (o if isinstance(o, list) else [o]):
            if isinstance(x, dict):
                found.append(x)
                inner = x.get("offers")
                found += [i for i in (inner if isinstance(inner, list) else [inner]) if isinstance(i, dict)]
    return found


def _offer_price(o: dict):
    for k in ("price", "lowPrice"):
        if o.get(k) not in (None, ""):
            return parse_price(o[k])
    spec = o.get("priceSpecification")
    for s in (spec if isinstance(spec, list) else [spec]):
        if isinstance(s, dict) and s.get("price") not in (None, ""):
            return parse_price(s["price"])
    return None


def _availability(o: dict) -> str:
    a = o.get("availability") or ""
    return re.sub(r".*[/#:]", "", str(a)).strip().lower()


def parse_product_page(html: str, url: str) -> dict | None:
    soup = BeautifulSoup(html, "html.parser")
    prod = None
    for s in soup.find_all("script", attrs={"type": re.compile(r"ld\+json", re.I)}):
        raw = (s.string or s.get_text() or "").strip()
        if not raw:
            continue
        try:
            data = json.loads(raw, strict=False)
        except ValueError:
            try:
                data = json.loads(re.sub(r",\s*([}\]])", r"\1", raw), strict=False)
            except ValueError:
                continue
        for node in _walk(data):
            if _types(node) & {"product", "productgroup"} and node.get("name"):
                prod = node
                break
        if prod:
            break

    name = price = avail = image = currency = desc = None
    if prod:
        name = str(prod.get("name")).strip()
        offers = _offers(prod)
        priced = [(p, o) for o in offers if (p := _offer_price(o)) is not None]
        if priced:
            orderable = [x for x in priced if _availability(x[1]) in AVAILABLE] or priced
            price, best = min(orderable, key=lambda x: x[0])
            avail = _availability(best)
            currency = best.get("priceCurrency")
        elif offers:
            avail = _availability(offers[0])
        image = _first_url(prod.get("image"))
        desc = prod.get("description")

    # Fallbacks: microdata and OpenGraph/product meta tags
    def meta(*names):
        for n in names:
            el = soup.find("meta", attrs={"property": n}) or soup.find("meta", attrs={"name": n}) \
                 or soup.find(attrs={"itemprop": n})
            if el is not None:
                v = el.get("content") or el.get("href") or el.get_text(" ", strip=True)
                if v:
                    return v
        return None

    name = name or meta("og:title", "name")
    if price is None:
        price = parse_price(meta("product:price:amount", "og:price:amount", "price"))
    avail = avail or re.sub(r".*[/#:]", "", str(meta("product:availability", "og:availability", "availability") or "")).lower().replace(" ", "")
    image = image or meta("og:image")
    currency = currency or meta("product:price:currency", "priceCurrency") or "EUR"
    if not name or (price is None and not avail):
        return None

    hints = []
    for el in soup.find_all(True, attrs={"class": STATUS_HINT}):
        txt = el.get_text(" ", strip=True)
        if 0 < len(txt) < 200:
            hints.append(txt)
        if len(hints) >= 8:
            break
    for el in soup.find_all(["button", "input"], attrs={"type": "submit"})[:4]:
        hints.append(el.get_text(" ", strip=True) or el.get("value", ""))

    available = True if avail in AVAILABLE else False if avail in UNAVAILABLE else None
    canonical = soup.find("link", rel="canonical")
    page_url = urljoin(url, canonical["href"]) if canonical and canonical.get("href") else url
    return {
        "external_id": page_url,
        "title": re.sub(r"\s+", " ", name)[:500],
        "url": page_url,
        "image": urljoin(url, image) if image else None,
        "price": price,
        "currency": str(currency)[:3].upper(),
        "available": available,
        "status_text": ("preorder " if avail in ("preorder", "presale") else "") + f"{avail} " + " | ".join(hints)[:600],
        "tags": "",
        "text": BeautifulSoup(desc, "html.parser").get_text(" ", strip=True)[:800] if isinstance(desc, str) else "",
    }


# --------------------------------------------------------------- adapter
def jsonld(shop: dict) -> list[dict]:
    cfg = shop.setdefault("config", {})
    base = shop["base_url"].rstrip("/")
    if not cfg.get("search_url") and not cfg.get("listing_urls"):
        cfg.update(find_listing(base))
        shop["_detected"] = {"platform": "jsonld", "config": cfg}
        if not cfg:
            raise RuntimeError("No search page or One Piece category found for this shop.")

    terms = shop.get("_terms") or []
    queries = list(dict.fromkeys(list(cfg.get("queries", DEFAULT_QUERIES)) + [search_query(t) for t in terms]))
    pages = []
    if cfg.get("search_url"):
        pages += [cfg["search_url"].replace("{q}", quote_plus(q)) for q in queries]
    pages += cfg.get("listing_urls", [])

    links, errors = [], []
    for p in pages:
        try:
            r = _get(p)
            for l in _product_links(r.text, r.url, base, terms):
                if l not in links:
                    links.append(l)
        except RuntimeError as e:
            errors.append(str(e))
        time.sleep(PAGE_DELAY)
    if not links and errors:
        raise RuntimeError("; ".join(errors)[:400])

    items = []
    # term-matched links were sorted first on each page; keep that priority overall
    links.sort(key=lambda l: 0 if term_matches(_slug_text(l), terms) else 1)
    for link in links[: int(cfg.get("max_products", 80))]:
        try:
            item = parse_product_page(_get(link).text, link)
            if item:
                items.append(item)
        except RuntimeError:
            pass  # one broken product page is not a shop failure
        time.sleep(PAGE_DELAY)
    return items


def run_auto(shop: dict) -> list[dict]:
    platform, cfg = detect(shop)
    shop["platform"] = platform
    shop["config"] = {**(shop.get("config") or {}), **cfg}
    shop["_detected"] = {"platform": platform, "config": shop["config"]}
    fn = {"shopify": shopify, "woocommerce": woocommerce, "jsonld": jsonld}[platform]
    return fn(shop)
