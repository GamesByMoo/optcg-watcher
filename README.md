# OPTCG Preorder Watcher

Watches German and Dutch web shops (a starting list of DE/NL TCG shops, plus any shop you add by
pasting its URL) for **English One Piece TCG displays, cases and special
products**, lists what is up for preorder and what is buyable right now, keeps a price history,
and e-mails you when a preorder drops below your limit.

```
GitHub Actions (every 45 min)                 STRATO webspace (your domain)
┌───────────────────────────┐   GET config   ┌──────────────────────────────┐
│ scraper/main.py           │ ─────────────▶ │ api/config.php   (token)     │
│  · auto-detects each shop │                │ api/ingest.php   (token)     │──▶ MySQL
│  · Shopify / WooCommerce  │ ─────────────▶ │   → price history            │
│  · any shop via schema.org│                │                              │
│  · EN / type / preorder   │  POST results  │   → alert rules → mail()     │
└───────────────────────────┘                │ index.php / settings.php     │◀── you (password)
                                             └──────────────────────────────┘
```

**Why the scraper talks to a PHP API instead of the database directly:** on STRATO shared
hosting the MySQL database only accepts connections from STRATO's own web servers, not from the
internet. GitHub Actions therefore sends its results to `api/ingest.php`, protected by a long
secret token, and PHP writes them into the database.

---

## Setup – automatic (recommended, works from a phone)
GitHub uploads the web app to STRATO and creates the database for you
(`.github/workflows/deploy.yml`). You only enter your access data as GitHub secrets:

| Secret | Where to find it |
|---|---|
| `STRATO_SFTP_HOST`, `STRATO_SFTP_USER`, `STRATO_SFTP_PASSWORD` | STRATO login → Datenbanken und Webspace → **SFTP & SSH** |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | STRATO login → Datenbanken und Webspace → **Datenbankverwaltung** |
| `ADMIN_PASSWORD` | your choice (min. 10 characters) – the login password for the web app |
| `OPT_API_TOKEN` | a random string of 32+ characters |
| `OPT_API_URL` | `https://your-domain.de/optcg` |
| `MAIL_FROM` | a mailbox on your domain, e.g. `preorder-alarm@your-domain.de` |

Optional repository *variable* `REMOTE_DIR` (default `optcg`): the folder on the webspace.
Then *Actions → Deploy web app to STRATO → Run workflow*. Every later change to `web/` is
deployed automatically. `install.php` is not uploaded in this mode.

## Setup – manual


### 1. STRATO
1. **Database:** Kunden-Login → *Datenbanken und Webspace* → *Datenbankverwaltung* → create a
   database. Note host, database name, user and password.
2. **SSL:** make sure the free SSL certificate is active for the domain (the app forces HTTPS).
3. **Mailbox:** create a mailbox on your domain to use as the sender, e.g.
   `preorder-alarm@your-domain.de` (STRATO only delivers `mail()` from your own domain).

### 2. Upload the web app
1. Upload the **contents** of `web/` via SFTP into a folder, e.g. `/optcg/`
   (→ `https://your-domain.de/optcg/`).
2. On the server, copy `config.sample.php` to `config.php` and fill in the database values and
   `MAIL_FROM`.
3. Open `https://your-domain.de/optcg/install.php`:
   - generate the **password hash** → paste into `config.php` as `ADMIN_PASSWORD_HASH`
   - copy the **API token** → paste into `config.php` as `API_TOKEN` (keep it for step 3)
   - click **Create tables** (adds the starting shop list from `shops-de-nl.txt` and one example
     price rule)
4. **Delete `install.php`** from the server.

### 3. GitHub
1. Create a **public** repository and push this project (`web/config.php` is git-ignored, the
   token lives only in GitHub Secrets). Public repositories get unlimited free Actions minutes –
   with dozens of shops a check takes several minutes, which would exceed the 2,000 free minutes
   of a private repository. (Private works too if you pay for minutes or check fewer shops.)
2. Repository → *Settings* → *Secrets and variables* → *Actions* → add:
   - `OPT_API_URL` = `https://your-domain.de/optcg` (no trailing slash)
   - `OPT_API_TOKEN` = the same token as in `config.php`
3. *Actions* tab → **OPTCG preorder check** → *Run workflow* for a first test.
   After that it runs automatically every 45 minutes.

### 4. Use it
Log in at `https://your-domain.de/optcg/`, then under **Settings**:
- enter the e-mail address(es) for alerts and click **Send test mail**
- create price rules, e.g. *"OP-15 displays ≤ 95 €"* (type Display, title contains `OP-15`)
- check the shop list – each shop shows its last check, errors and the number of offers found

Views: **Preorders**, **Buy now** (in stock), **All** (incl. sold out). Each offer shows the
previous price, a *Cheapest* badge per set and highlights offers that hit one of your rules.
*history* shows the price history and the same set in the other shops.

---

## How alerts work
- After each check, every active **preorder** offer (or in-stock, if the rule says so) is compared
  with your rules. Match = product type, country, optional title text and price ≤ limit.
- All matches of one check go out in **one e-mail**.
- An offer is only mailed again if its price **drops below the last alerted price**, or if it was
  sold out and becomes orderable again. You won't get the same offer every 45 minutes.

## Adding shops
*Settings → Shops → Add shops:* paste one or more shop URLs, one per line. That's all – on the
next check the scraper **detects by itself** how to read each shop and remembers it:

1. **Shopify** → public product feed (`/products.json`)
2. **WooCommerce** → public Store API
3. **Any other shop** (Shopware, Magento, PrestaShop, JTL, Lightspeed, Gambio, OpenCart, custom…)
   → **Universal** mode: it finds the shop's own search, searches for One Piece displays / booster
   boxes / English products, opens the matching product pages and reads the **schema.org product
   data** that shops embed for Google (name, price, availability – including schema.org's
   `PreOrder` status). Falls back to microdata / OpenGraph tags, and to One Piece category pages if
   a shop has no usable search.

Lines can also carry a name and country: `Kaartenwinkel | https://kaartenwinkel.nl | NL`
(otherwise the name comes from the domain and `.nl`/`.be` → NL, everything else → DE).

If a shop fails, *Settings → Shops → Problems* shows the reason. Options: **re-detect**, edit its
config (e.g. add `"listing_urls": ["https://shop.de/one-piece-englisch"]` if its search is odd), or
use the **Manual (CSS selectors)** platform as a last resort. Shops that fail 20 checks in a row
(~15 h) are paused automatically so they don't waste run time.

**Try any shop before adding it** (on your PC, Python 3.10+):
```bash
pip install -r scraper/requirements.txt
python scraper/main.py --url https://www.some-shop.de        # auto-detect + show what it finds
OPT_API_URL=https://your-domain.de/optcg OPT_API_TOKEN=... python scraper/main.py --only-shop 6 --dry-run
```
`--dry-run` prints what would be found without saving anything. Offline test with fixture shops:
`python tests/serve_fixtures.py` in one terminal, then
`python scraper/main.py --shops-file tests/shops.local.json --dry-run` in another.

## Search terms
*Settings → Search terms* holds the sets/products you want looked for specifically – one per line
(pre-filled with EB05–EB08, OP-19–OP-23, OP-PEB, OP-PEB01). Each term:
- is **searched in every shop** that uses the universal reader or WooCommerce (Shopify shops
  already deliver their whole catalogue), and those product pages are read first;
- **keeps matching products** even if their title doesn't say "display" etc. – single booster
  packs and accessories are still excluded;
- gets a **★ badge** in the lists and a **quick-filter chip** above them.

Set codes are matched in any spelling – `EB05`, `EB-05`, `EB 05` and `EB-5` are the same – but
only as codes, so `OP-2` never matches `OP-20`. `OP-PEB` matches `PEB-01` titles too. The same
matching is used by price rules ("Title contains") and the search box. Each term adds one search
per shop and run, so keep the list to what you actually want (max. 60).

Try terms without changing the web app: `python scraper/main.py --url https://shop.de --terms "OP-20,EB05"`.

## Tuning what counts as English / display / preorder
All keyword rules are in `scraper/classify.py` (English/Japanese markers, display/case/special
patterns, accessory exclusions, preorder words in DE/NL/EN). If an item lands in the wrong bucket,
adjust a pattern there and push – the next run uses it.

## Good to know
- **Timing:** GitHub runs scheduled workflows on a best-effort basis; runs are often a few minutes
  late. The web app warns you if no check arrived for 3 hours.
- **Run time:** shops are checked 15 at a time (`OPT_WORKERS`), one request per second per shop.
  A run stops starting new shops after 30 minutes (`OPT_TIME_BUDGET`) so it never overlaps the next
  one; skipped shops are noted in *Recent checks*. Add `OPT_WORKERS: "25"` to the workflow's `env:`
  if that happens.
- **Inactivity:** GitHub disables schedules in repositories without activity for 60 days. If that
  happens, re-enable the workflow in the *Actions* tab.
- **Blocked shops:** some shops block cloud/datacenter IPs (Cloudflare etc.). They show up as
  *failed* with the error in *Settings → Shops → Problems*.
- **Not included on purpose:** Cardmarket, Amazon and bol.com – strong bot protection and terms
  that forbid scraping. The scraper sends at most one request per second per shop.
- **Security:** login is rate-limited (5 failures / 15 min per IP), sessions expire after 12 h of
  inactivity, all forms use CSRF tokens, `config.php` and `lib/` are blocked by `.htaccess`.

## Files
```
.github/workflows/scrape.yml   schedule (every 45 min) + manual start
scraper/main.py                runner: config → scrape → classify → POST
scraper/adapters.py            Shopify, WooCommerce, manual HTML adapters
scraper/universal.py           auto-detection + universal schema.org adapter
scraper/classify.py            English / product type / preorder rules
web/                           upload this to STRATO
  index.php, history.php       offer lists, price history
  settings.php                 alerts, rules, shops, run log
  login.php, logout.php        password login
  api/config.php, ingest.php   token-protected API for GitHub Actions
  install.php                  one-time setup (delete afterwards)
  schema.sql                   database tables
  shops-de-nl.txt              starting shop list (imported by install.php)
tests/                         fixture shops (Shopify, HTML, Shopware/JSON-LD) for offline testing
```

## Upgrading from the first version
If you already installed the first version, run this once in phpMyAdmin, upload the new files
(keep your `config.php`), then paste `shops-de-nl.txt` into *Settings → Shops → Add shops*:
```sql
ALTER TABLE shops MODIFY platform VARCHAR(20) NOT NULL DEFAULT 'auto',
  ADD fail_streak INT NOT NULL DEFAULT 0, ADD UNIQUE KEY uq_base_url (base_url(190));
```
