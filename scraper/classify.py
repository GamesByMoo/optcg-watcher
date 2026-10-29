"""Decides whether a shop item is a relevant English One Piece TCG product,
what kind of product it is and whether it is a preorder.

Everything here is plain keyword matching, so it is easy to tune: if an item
is classified wrongly, add or adjust a pattern below.
"""
import re

# --- Is it One Piece TCG at all? -------------------------------------------
ONE_PIECE = re.compile(r"one\s*piece", re.I)

# --- Language ---------------------------------------------------------------
# Positive English markers (title, tags, URL or description).
ENGLISH = re.compile(
    r"\b(english|englisch|engelse?|engels|eng|en)\b|\(en\)|\[en\]|-en\b|_en\b",
    re.I,
)
# Markers for other languages. If one of these is in the TITLE the item is dropped.
OTHER_LANG = re.compile(
    r"\b(japanese|japanisch|japans|jap|jp|jpn|chinese|chinesisch|chinees|"
    r"simplified|traditional chinese|french|franz(ö|oe)sisch|frans|fr|korean|koreanisch)\b"
    r"|\(jp\)|\[jp\]|\(cn\)|\[cn\]|\(fr\)|\[fr\]",
    re.I,
)

# --- Product type -----------------------------------------------------------
ACCESSORY = re.compile(
    r"sleeve|h(ü|ue)llen|playmat|spielmatte|speelmat|deck\s*box|deckbox|binder|album|"
    r"ordner|portfolio|toploader|top\s*loader|card\s*case|storage|aufbewahrung|"
    r"magnetic|acrylic|acryl|figur|figure|plush|pl(ü|ue)sch|poster|keychain",
    re.I,
)
CASE = re.compile(r"\bcase\b|12\s*x\s*display|12\s*displays|\(12 (booster )?box", re.I)
DISPLAY = re.compile(
    r"display|booster\s*-?\s*box|boosterbox|booster\s+bundle\s+box|"
    r"(24|20|10)\s*(x\s*)?booster(s| packs?)?\b(?!\s*pack\b)|extra\s+booster\s+box|"
    r"premium\s+booster\s+box",
    re.I,
)
SPECIAL = re.compile(
    r"premium|anniversary|jubil(ä|ae)um|gift|collection|illustration\s*box|double\s*pack|"
    r"treasure|special|limited|\btin\b|starter\s*deck|ultra\s*deck|learn\s*together|"
    r"devil\s*fruit|card\s*set|\bset\b|bundle|box\s*set|celebration|championship|"
    r"tournament\s*pack|kit\b",
    re.I,
)
SINGLE_PACK = re.compile(r"booster\s*pack|\bbooster\b(?!.*(box|display))|\bpack\b", re.I)

# --- Preorder detection -----------------------------------------------------
PREORDER = re.compile(
    r"pre-?\s?order|preorder|vorbestell|vorverkauf|vorbesteller|pre-?sale|presale|"
    r"voorverkoop|voorbestel|pre-?release|erscheint|erscheinungs|release\s*date|"
    r"verf(ü|ue)gbar\s*ab|lieferbar\s*ab|beschikbaar\s*vanaf|leverbaar\s*vanaf|"
    r"verschijnt",
    re.I,
)
# Listed, but cannot be ordered (yet): announcement pages, "notify me" buttons etc.
NOT_ORDERABLE = re.compile(
    r"coming\s*soon|demn(ä|ae)chst|bald\s*(verf(ü|ue)gbar|erh(ä|ae)ltlich)|binnenkort|"
    r"benachrichtig|notify\s*me|e-?mail\s*(me|bei|wenn)|mail\s*me\s*when|"
    r"houd\s*me\s*op\s*de\s*hoogte|laat\s*het\s*me\s*weten|op\s*de\s*hoogte|"
    r"nicht\s*(mehr\s*)?bestellbar|niet\s*(meer\s*)?bestelbaar|not\s*(yet\s*)?(available\s*for\s*)?order|"
    r"noch\s*nicht\s*(bestellbar|verf(ü|ue)gbar|vorbestellbar)|nog\s*niet\s*(bestelbaar|beschikbaar)|"
    r"preis\s*(folgt|auf\s*anfrage)|prijs\s*volgt|price\s*tba|tba\b|"
    r"vorbestellung\s*(noch\s*nicht|bald|ab\s*\d)|ank(ü|ue)ndigung|announcement",
    re.I,
)
SOLD_OUT = re.compile(
    r"ausverkauft|sold\s*out|uitverkocht|nicht\s*verf(ü|ue)gbar|niet\s*beschikbaar|"
    r"out\s*of\s*stock|nicht\s*auf\s*lager|niet\s*op\s*voorraad|momentan\s*nicht",
    re.I,
)

SET_CODE = re.compile(r"\b(OP|EB|PRB|PEB|ST|DP|LD)\s*-?\s*(\d{1,2})\b", re.I)
SINGLE_PACK_ONLY = re.compile(r"booster\s*pack|\bsingle\s*pack|\b1\s*x\s*booster\b|einzel", re.I)


def set_code(text: str) -> str | None:
    m = SET_CODE.search(text or "")
    if not m:
        return None
    return f"{m.group(1).upper()}-{int(m.group(2)):02d}"


# ------------------------------------------------------------ search terms
def _compact(text: str) -> str:
    return re.sub(r"[^a-z0-9]", "", (text or "").lower())


def _term_core(term: str) -> str:
    """'OP-PEB' -> 'PEB' (an 'OP-' prefix in front of letters is not part of the code)."""
    return re.sub(r"(?i)^op\s*-?\s*(?=[a-z])", "", term.strip())


def search_query(term: str) -> str:
    """What to type into a shop's search box for a term:
    set codes in the usual 'EB-05' form, other words prefixed with 'one piece'."""
    core = _term_core(term)
    if SET_CODE.fullmatch(core.strip()):  # the term is exactly a set code, e.g. EB05
        return set_code(core)
    return core if ONE_PIECE.search(core) else f"one piece {core}"


def term_matches(title: str, terms) -> str | None:
    """Returns the first search term the title matches. 'EB05', 'EB-05', 'EB 05' and
    'EB-5' are treated as the same code; other terms match ignoring spaces/dashes."""
    if not terms:
        return None
    codes = {f"{m.group(1).upper()}-{int(m.group(2)):02d}" for m in SET_CODE.finditer(title or "")}
    ct = _compact(title)
    for term in terms:
        core_term = _term_core(term).strip()
        if SET_CODE.fullmatch(core_term):  # codes only match as codes: OP-2 never matches OP-20
            if set_code(core_term) in codes:
                return term
            continue
        core = _compact(_term_core(term))
        if core and core in ct:
            return term
    return None


def is_one_piece(title: str, extra: str = "") -> bool:
    return bool(ONE_PIECE.search(title) or (SET_CODE.search(title) and ONE_PIECE.search(extra)))


def is_english(title: str, extra: str, assume_english: bool) -> bool:
    """assume_english: shop config says everything listed there is English
    (e.g. a collection URL that only contains English products)."""
    if OTHER_LANG.search(title):
        return False
    if ENGLISH.search(title) or ENGLISH.search(extra):
        return True
    return assume_english


def product_type(title: str) -> str:
    # remove set codes first, so the "20" in "OP-20 Booster Pack" is not read as "20 boosters"
    title = SET_CODE.sub(" ", title)
    if ACCESSORY.search(title):
        return "other"
    if CASE.search(title) and DISPLAY.search(title):
        return "case"
    if DISPLAY.search(title):
        return "display"
    if SPECIAL.search(title):
        return "special"
    return "other"


def status(available: bool | None, text: str, title: str) -> str:
    """available: what the shop says about orderability (None = unknown).
    Returns 'preorder', 'available', 'sold_out' or 'not_orderable'."""
    if available is False or (available is None and SOLD_OUT.search(text)):
        return "sold_out"
    if NOT_ORDERABLE.search(text) or NOT_ORDERABLE.search(title):
        return "not_orderable"
    if available is None:
        # nothing says it can be bought: only trust an explicit preorder signal
        return "preorder" if (PREORDER.search(title) or PREORDER.search(text)) else "not_orderable"
    return "preorder" if (PREORDER.search(title) or PREORDER.search(text)) else "available"


def classify(item: dict, assume_english: bool = False, terms=()) -> dict | None:
    """Returns the enriched item, or None if it is not relevant.
    terms: the user's search terms - matching products are kept even if their
    title doesn't say 'display' etc. (single booster packs and accessories excepted)."""
    title = item.get("title", "")
    extra = " ".join(str(item.get(k, "")) for k in ("tags", "url", "text"))
    if not is_one_piece(title, extra):
        return None
    if not is_english(title, extra, assume_english):
        return None
    ptype = product_type(title)
    matched = term_matches(title, terms)
    if ptype == "other" and matched and not ACCESSORY.search(title) and not SINGLE_PACK_ONLY.search(title):
        ptype = "special"
    if ptype == "other":
        return None
    item["matched_term"] = matched
    item["product_type"] = ptype
    item["set_code"] = set_code(title)
    # Only short, specific signals (tags, badges, button text) are used for the
    # preorder check - full descriptions often mention "pre-order" generically.
    item["status"] = status(item.get("available"), item.get("status_text", ""), title)
    return item
