<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/shops.php';
require_login();

$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    switch ($a) {
        case 'general':
            $emails = array_filter(array_map('trim', preg_split('/[,;]/', (string)($_POST['alert_email'] ?? ''))));
            foreach ($emails as $e) {
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid e-mail: $e";
            }
            $types = array_intersect((array)($_POST['types'] ?? []), ['display', 'case', 'special']);
            if (!$errors) {
                set_setting('alert_email', implode(', ', $emails));
                set_setting('product_types', implode(',', $types ?: ['display']));
                flash('Settings saved.');
            }
            break;

        case 'terms':
            $terms = array_values(array_unique(array_filter(array_map(
                static fn($t) => mb_substr(trim($t), 0, 60),
                preg_split('/[\r\n,;]+/', (string)($_POST['search_terms'] ?? ''))
            ))));
            if (count($terms) > 60) {
                $errors[] = 'Please keep it to 60 search terms or fewer – each term is an extra search in every shop.';
                break;
            }
            set_setting('search_terms', implode("\n", $terms));
            flash(count($terms) . ' search term(s) saved. They are used from the next check on.');
            break;

        case 'testmail':
            $to = setting('alert_email');
            $ok = $to !== '' && send_mail($to, 'OPTCG Watcher test mail', alert_mail_html([[
                'title' => 'Test – One Piece Card Game OP-99 Booster Display (EN)', 'url' => 'https://example.com',
                'shop' => 'Example shop', 'country' => 'DE', 'status' => 'preorder', 'price' => 89.99,
                'old_price' => null, 'rule' => 'Test', 'max_price' => 100,
            ]]));
            flash($ok ? "Test mail sent to $to." : 'Sending failed. Set an alert e-mail and check MAIL_FROM in config.php.');
            break;

        case 'rule_save':
            $label = trim((string)($_POST['label'] ?? ''));
            $max = str_replace(',', '.', (string)($_POST['max_price'] ?? ''));
            $ptype = in_array($_POST['product_type'] ?? '', ['any', 'display', 'case', 'special'], true) ? $_POST['product_type'] : 'any';
            $country = in_array($_POST['country'] ?? '', ['any', 'DE', 'NL'], true) ? $_POST['country'] : 'any';
            $statuses = in_array($_POST['statuses'] ?? '', ['preorder', 'available', 'both'], true) ? $_POST['statuses'] : 'preorder';
            $match = mb_substr(trim((string)($_POST['match_text'] ?? '')), 0, 100);
            if ($label === '' || !is_numeric($max) || (float)$max <= 0) {
                $errors[] = 'A rule needs a name and a price above 0.';
                break;
            }
            if ($id) {
                $pdo->prepare('UPDATE alert_rules SET label=?, match_text=?, product_type=?, country=?, statuses=?, max_price=? WHERE id=?')
                    ->execute([$label, $match, $ptype, $country, $statuses, (float)$max, $id]);
            } else {
                $pdo->prepare('INSERT INTO alert_rules (label, match_text, product_type, country, statuses, max_price) VALUES (?,?,?,?,?,?)')
                    ->execute([$label, $match, $ptype, $country, $statuses, (float)$max]);
            }
            flash('Rule saved. Matching offers that were not mailed before will be sent after the next check.');
            break;

        case 'rule_toggle':
            $pdo->prepare('UPDATE alert_rules SET active = 1 - active WHERE id = ?')->execute([$id]);
            flash('Rule updated.');
            break;

        case 'rule_delete':
            $pdo->prepare('DELETE FROM alert_rules WHERE id = ?')->execute([$id]);
            flash('Rule deleted.');
            break;

        case 'shop_save':
            $url = shop_normalize_url((string)($_POST['base_url'] ?? '')) ?? '';
            $name = trim((string)($_POST['name'] ?? '')) ?: ($url ? shop_name_from_url($url) : '');
            $platform = array_key_exists($_POST['platform'] ?? '', SHOP_PLATFORMS) ? $_POST['platform'] : 'auto';
            $country = in_array($_POST['country'] ?? '', ['DE', 'NL'], true) ? $_POST['country'] : ($url ? shop_country_from_url($url) : 'DE');
            $cfgRaw = trim((string)($_POST['config'] ?? '')) ?: '{}';
            $cfg = json_decode($cfgRaw, true);
            if ($url === '') $errors[] = 'Please enter a valid shop URL.';
            if (!is_array($cfg)) $errors[] = 'Config is not valid JSON: ' . json_last_error_msg();
            if ($platform === 'auto') $cfg = []; // fresh detection on the next run
            if ($platform === 'html' && is_array($cfg)) {
                foreach (['urls', 'item', 'title', 'price'] as $k) {
                    if (empty($cfg[$k])) $errors[] = "HTML shops need \"$k\" in the config.";
                }
            }
            if ($errors) break;
            $cfgJson = json_encode($cfg ?: new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            try {
                if ($id) {
                    $pdo->prepare('UPDATE shops SET name=?, country=?, platform=?, base_url=?, config=?, fail_streak=0 WHERE id=?')
                        ->execute([$name, $country, $platform, $url, $cfgJson, $id]);
                } else {
                    $pdo->prepare('INSERT INTO shops (name, country, platform, base_url, config, active) VALUES (?,?,?,?,?,1)')
                        ->execute([$name, $country, $platform, $url, $cfgJson]);
                }
            } catch (PDOException $e) {
                $errors[] = 'This shop URL is already in the list.';
                break;
            }
            flash('Shop saved. It will be checked in the next run.');
            break;

        case 'shop_bulk':
            [$added, $dupes, $invalid] = shop_import($pdo, (string)($_POST['lines'] ?? ''));
            flash("$added shop(s) added, $dupes already listed" . ($invalid ? ', ' . count($invalid) . ' line(s) not understood: ' . implode(' · ', array_slice($invalid, 0, 5)) : '') . '.');
            break;

        case 'shop_redetect':
            $pdo->prepare("UPDATE shops SET platform='auto', config='{}', fail_streak=0, active=1 WHERE id = ?")->execute([$id]);
            flash('The shop will be detected again in the next run.');
            break;

        case 'shop_toggle':
            $pdo->prepare('UPDATE shops SET active = 1 - active, fail_streak = 0 WHERE id = ?')->execute([$id]);
            flash('Shop updated.');
            break;

        case 'shop_delete':
            $pdo->prepare('DELETE FROM shops WHERE id = ?')->execute([$id]);
            flash('Shop and its offers deleted.');
            break;
    }
    if (!$errors) {
        header('Location: settings.php');
        exit;
    }
}

$rules = $pdo->query('SELECT * FROM alert_rules ORDER BY active DESC, max_price')->fetchAll();
$shopFilter = in_array($_GET['shops'] ?? '', ['problems', 'paused', 'all'], true) ? $_GET['shops'] : 'all';
$shopWhere = ['problems' => 'WHERE active = 1 AND last_error IS NOT NULL', 'paused' => 'WHERE active = 0', 'all' => ''][$shopFilter];
$shops = $pdo->query("SELECT * FROM shops $shopWhere ORDER BY active DESC, last_error IS NULL, country, name")->fetchAll();
$shopStats = $pdo->query("SELECT COUNT(*) total, SUM(active) active, SUM(active = 1 AND last_error IS NOT NULL) failing,
                                 SUM(active = 1 AND platform = 'auto') pending, SUM(active = 0) paused FROM shops")->fetch();
$runs = $pdo->query('SELECT * FROM runs ORDER BY id DESC LIMIT 15')->fetchAll();
$types = explode(',', setting('product_types', 'display,case,special'));

$editRule = ['id' => 0, 'label' => '', 'match_text' => '', 'product_type' => 'display', 'country' => 'any', 'statuses' => 'preorder', 'max_price' => ''];
$editShop = ['id' => 0, 'name' => '', 'country' => 'DE', 'platform' => 'auto', 'base_url' => '', 'config' => '{}'];
foreach ($rules as $r) if ((int)$r['id'] === (int)($_GET['rule'] ?? -1)) $editRule = $r;
foreach ($shops as $s) if ((int)$s['id'] === (int)($_GET['shop'] ?? -1)) $editShop = $s;
if (!empty($_POST) && $errors) {
    if (($_POST['action'] ?? '') === 'rule_save') $editRule = array_merge($editRule, $_POST);
    if (($_POST['action'] ?? '') === 'shop_save') $editShop = array_merge($editShop, $_POST);
}
$prettyCfg = static function ($c): string {
    $d = json_decode((string)$c, true);
    return $d ? json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '{}';
};
$sel = static fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';

page_header('Settings', 'settings');
foreach ($errors as $e): ?><p class="flash err"><?= h($e) ?></p><?php endforeach; ?>

<h1>Settings</h1>

<section class="card">
  <h2>Alerts</h2>
  <form method="post" class="row-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="general">
    <label class="grow">Send alerts to (comma-separated)
      <input type="text" name="alert_email" value="<?= h(setting('alert_email')) ?>" placeholder="you@example.com"></label>
    <fieldset><legend>Track</legend>
      <?php foreach (['display' => 'Displays', 'case' => 'Cases', 'special' => 'Special products'] as $k => $l): ?>
        <label class="check"><input type="checkbox" name="types[]" value="<?= $k ?>" <?= in_array($k, $types, true) ? 'checked' : '' ?>> <?= $l ?></label>
      <?php endforeach; ?>
    </fieldset>
    <button>Save</button>
  </form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="testmail"><button class="secondary">Send test mail</button></form>
</section>

<section class="card" id="terms">
  <h2>Search terms</h2>
  <p class="muted">Sets and products to look for specifically – one per line. Each term is searched in every shop
    (in addition to the general One Piece display search), matching products are always kept, and they are marked
    with ★ in the lists. Set codes are matched in any spelling: <code>EB05</code> = <code>EB-05</code> = <code>EB 5</code>.
    A range like <code>ST37-ST50</code> searches every code in it and appears as one filter button.</p>
  <form method="post" class="terms-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="terms">
    <textarea name="search_terms" rows="8" spellcheck="false" placeholder="OP-20&#10;EB05&#10;Anniversary"><?= h(implode("\n", search_terms())) ?></textarea>
    <button>Save search terms</button>
  </form>
  <p class="muted small">Tip: price rules can use the same terms – e.g. a rule with "Title contains" <code>OP-20</code> and max 95 €.</p>
</section>

<section class="card">
  <h2>Price rules</h2>
  <p class="muted">You get one e-mail per check listing every offer at or below a rule's limit.
     The same offer is only mailed again if its price drops further.</p>
  <table class="grid">
    <tr><th>Rule</th><th>Matches</th><th class="r">Max price</th><th></th></tr>
    <?php foreach ($rules as $r): ?>
      <tr class="<?= $r['active'] ? '' : 'off' ?>">
        <td><?= h($r['label']) ?></td>
        <td class="muted"><?= h($r['product_type']) ?> · <?= h($r['country']) ?> · <?= h($r['statuses']) ?><?= $r['match_text'] !== '' ? ' · "' . h($r['match_text']) . '"' : '' ?></td>
        <td class="r"><?= h(eur($r['max_price'])) ?></td>
        <td class="actions">
          <a href="settings.php?rule=<?= (int)$r['id'] ?>#ruleform">edit</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button name="action" value="rule_toggle" class="link"><?= $r['active'] ? 'pause' : 'enable' ?></button>
            <button name="action" value="rule_delete" class="link danger" onclick="return confirm('Delete rule?')">delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <form method="post" id="ruleform" class="grid-form">
    <h3><?= $editRule['id'] ? 'Edit rule' : 'New rule' ?></h3>
    <?= csrf_field() ?><input type="hidden" name="action" value="rule_save"><input type="hidden" name="id" value="<?= (int)$editRule['id'] ?>">
    <label>Name <input name="label" value="<?= h($editRule['label']) ?>" required placeholder="OP-15 display cheap"></label>
    <label>Title contains <input name="match_text" value="<?= h($editRule['match_text']) ?>" placeholder="optional, e.g. OP-15"></label>
    <label>Type <select name="product_type">
      <?php foreach (['any' => 'Any', 'display' => 'Display', 'case' => 'Case', 'special' => 'Special'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $sel($editRule['product_type'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label>Country <select name="country">
      <?php foreach (['any' => 'DE + NL', 'DE' => 'Germany', 'NL' => 'Netherlands'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $sel($editRule['country'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label>Status <select name="statuses">
      <?php foreach (['preorder' => 'Preorder only', 'available' => 'In stock only', 'both' => 'Both'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $sel($editRule['statuses'], $k) ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label>Max price (€) <input name="max_price" inputmode="decimal" value="<?= h((string)$editRule['max_price']) ?>" required placeholder="95"></label>
    <div class="buttons"><button>Save rule</button><?php if ($editRule['id']): ?> <a href="settings.php">cancel</a><?php endif; ?></div>
  </form>
</section>

<section class="card" id="shops">
  <h2>Shops</h2>
  <p class="muted">
    <?= (int)$shopStats['total'] ?> shops · <?= (int)$shopStats['active'] ?> active
    <?php if ($shopStats['pending']): ?>· <?= (int)$shopStats['pending'] ?> waiting for auto-detection<?php endif; ?>
    <?php if ($shopStats['failing']): ?>· <span class="bad"><?= (int)$shopStats['failing'] ?> failing</span><?php endif; ?>
    <?php if ($shopStats['paused']): ?>· <?= (int)$shopStats['paused'] ?> paused<?php endif; ?>
  </p>
  <nav class="subnav">
    <?php foreach (['all' => 'All', 'problems' => 'Problems', 'paused' => 'Paused'] as $k => $l): ?>
      <a href="settings.php?shops=<?= $k ?>#shops" class="<?= $shopFilter === $k ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="post" class="bulk">
    <?= csrf_field() ?><input type="hidden" name="action" value="shop_bulk">
    <label>Add shops – one per line, just the URL is enough (format: <code>Name | URL | DE/NL</code>, all parts optional except the URL)
      <textarea name="lines" rows="3" spellcheck="false" placeholder="https://www.some-tcg-shop.de&#10;Kaartenwinkel | https://kaartenwinkel.nl | NL"></textarea></label>
    <button>Add shops</button>
    <span class="muted small">The platform is detected automatically on the next check.</span>
  </form>

  <div class="table-wrap">
  <table class="grid">
    <tr><th>Shop</th><th>Last check</th><th class="r">Offers</th><th></th></tr>
    <?php foreach ($shops as $s): ?>
      <tr class="<?= $s['active'] ? '' : 'off' ?>">
        <td><span class="flag"><?= h($s['country']) ?></span> <a href="<?= h($s['base_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['name']) ?></a>
            <span class="muted">· <?= h($s['platform'] === 'auto' ? 'auto-detect pending' : (SHOP_PLATFORMS[$s['platform']] ?? $s['platform'])) ?></span>
            <?php if ($s['last_error']): ?><div class="err-text"><?= h($s['last_error']) ?><?= (int)$s['fail_streak'] > 1 ? ' (' . (int)$s['fail_streak'] . '× in a row)' : '' ?></div><?php endif; ?></td>
        <td class="muted"><?= $s['last_run_at'] ? h(date('d.m. H:i', strtotime($s['last_run_at']))) : '–' ?>
            <?= $s['last_error'] ? '<span class="bad">failed</span>' : ($s['last_run_at'] ? '<span class="good">ok</span>' : '') ?></td>
        <td class="r"><?= $s['last_item_count'] !== null ? (int)$s['last_item_count'] . ' <span class="muted">/ ' . (int)$s['last_raw_count'] . '</span>' : '–' ?></td>
        <td class="actions">
          <a href="settings.php?shop=<?= (int)$s['id'] ?>#shopform">edit</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button name="action" value="shop_redetect" class="link" title="Run auto-detection again">re-detect</button>
            <button name="action" value="shop_toggle" class="link"><?= $s['active'] ? 'pause' : 'enable' ?></button>
            <button name="action" value="shop_delete" class="link danger" onclick="return confirm('Delete shop and all its offers?')">delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$shops): ?><tr><td colspan="4" class="muted">No shops in this view.</td></tr><?php endif; ?>
  </table>
  </div>
  <p class="muted small">Offers = relevant English One Piece products / items read in that shop.
    Shops that fail 20 checks in a row are paused automatically.</p>

  <form method="post" id="shopform" class="grid-form">
    <h3><?= $editShop['id'] ? 'Edit shop' : 'Add a single shop (advanced)' ?></h3>
    <?= csrf_field() ?><input type="hidden" name="action" value="shop_save"><input type="hidden" name="id" value="<?= (int)$editShop['id'] ?>">
    <label>Shop URL <input name="base_url" value="<?= h($editShop['base_url']) ?>" required placeholder="https://shop.example.de"></label>
    <label>Name <input name="name" value="<?= h($editShop['name']) ?>" placeholder="from the URL if empty"></label>
    <label>Country <select name="country">
      <option value="DE" <?= $sel($editShop['country'], 'DE') ?>>Germany</option>
      <option value="NL" <?= $sel($editShop['country'], 'NL') ?>>Netherlands</option></select></label>
    <label>Platform <select name="platform">
      <?php foreach (SHOP_PLATFORMS as $k => $l): ?>
        <option value="<?= $k ?>" <?= $sel($editShop['platform'], $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
    <label class="full">Config (JSON – filled in automatically by auto-detection)
      <textarea name="config" rows="6" spellcheck="false"><?= h($prettyCfg($editShop['config'])) ?></textarea></label>
    <details class="full help"><summary>Config examples</summary>
<pre>Universal (any shop) – all optional, detected automatically:
{"search_url": "https://shop.de/search?search={q}",
 "listing_urls": ["https://shop.de/one-piece-englisch"],
 "queries": ["one piece display", "one piece booster box"], "max_products": 60}

Shopify (all optional):
{"collections": ["one-piece-tcg"], "max_pages": 30}

WooCommerce (all optional):
{"search": "one piece", "category": "one-piece", "max_pages": 10}

Manual CSS selectors (last resort):
{"urls": ["https://shop.de/one-piece-englisch?p={page}"], "pages": 3,
 "item": ".product-box", "title": ".product-name", "price": ".product-price",
 "status": ".delivery-info"}

Any platform: "assume_english": true – when the shop/listing only has English
products and titles don't say "EN".</pre>
    </details>
    <div class="buttons"><button>Save shop</button><?php if ($editShop['id']): ?> <a href="settings.php">cancel</a><?php endif; ?></div>
  </form>
</section>

<section class="card">
  <h2>Recent checks</h2>
  <table class="grid">
    <tr><th>Finished</th><th class="r">Shops ok / failed</th><th class="r">Offers</th><th class="r">Mails</th><th>Notes</th></tr>
    <?php foreach ($runs as $r): ?>
      <tr><td><?= h(date('d.m. H:i', strtotime($r['finished_at']))) ?></td>
          <td class="r"><?= (int)$r['shops_ok'] ?> / <span class="<?= $r['shops_failed'] ? 'bad' : '' ?>"><?= (int)$r['shops_failed'] ?></span></td>
          <td class="r"><?= (int)$r['items'] ?></td><td class="r"><?= (int)$r['alerts_sent'] ?></td>
          <td class="muted small"><?= nl2br(h($r['message'])) ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>
<?php page_footer();
