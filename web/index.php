<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

// "Not orderable" / "show again" buttons
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $hide = ($_POST['action'] ?? '') === 'hide' ? 1 : 0;
    db()->prepare('UPDATE products SET hidden = ? WHERE id = ?')->execute([$hide, (int)($_POST['id'] ?? 0)]);
    flash($hide ? 'Offer hidden – it no longer appears in the lists or alerts. Find it under "Hidden".' : 'Offer is shown again.');
    $back = (string)($_POST['back'] ?? 'index.php');
    header('Location: ' . (preg_match('#^index\.php(\?[^\r\n]*)?$#', $back) ? $back : 'index.php'));
    exit;
}

$view = in_array($_GET['view'] ?? '', ['preorder', 'available', 'all', 'hidden'], true) ? $_GET['view'] : 'preorder';
$country = in_array($_GET['country'] ?? '', ['DE', 'NL'], true) ? $_GET['country'] : '';
$type = in_array($_GET['type'] ?? '', ['display', 'case', 'special'], true) ? $_GET['type'] : '';
$q = trim((string)($_GET['q'] ?? ''));
$sort = in_array($_GET['sort'] ?? '', ['price', 'set', 'new'], true) ? $_GET['sort'] : 'set';

$where = ['p.active = 1'];
$args = [];
$where[] = $view === 'hidden' ? 'p.hidden = 1' : 'p.hidden = 0';
if ($view === 'preorder') {
    $where[] = "p.status = 'preorder'";
} elseif ($view === 'available') {
    $where[] = "p.status = 'available'";
}
$status = in_array($_GET['status'] ?? '', ['preorder', 'available', 'sold_out', 'not_orderable'], true) ? $_GET['status'] : '';
if ($view === 'all' && $status) { $where[] = 'p.status = ?'; $args[] = $status; }
if ($country) { $where[] = 's.country = ?'; $args[] = $country; }
if ($type) { $where[] = 'p.product_type = ?'; $args[] = $type; }
if ($q !== '') {
    // 'EB05' finds 'EB-05' / 'EB 05' titles too
    if ($rc = range_codes($q)) {
        $where[] = 'p.set_code IN (' . implode(',', array_fill(0, count($rc), '?')) . ')';
        array_push($args, ...$rc);
    } elseif ($code = canonical_code($q)) {
        $where[] = 'p.set_code = ?';
        $args[] = $code;
    } else {
        $where[] = "(p.title LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(p.title), '-', ''), ' ', ''), '_', '') LIKE ?)";
        array_push($args, "%$q%", '%' . compact_text(term_core($q)) . '%');
    }
}
$order = [
    'price' => 'p.price IS NULL, p.price ASC',
    'set' => "p.set_code IS NULL, p.set_code DESC, p.price ASC",
    'new' => 'p.first_seen DESC',
][$sort];

$sql = "SELECT p.*, s.name AS shop_name, s.country,
          (SELECT h.price FROM price_history h WHERE h.product_id = p.id AND h.price IS NOT NULL
             AND (p.price IS NULL OR h.price <> p.price) ORDER BY h.recorded_at DESC LIMIT 1) AS prev_price
        FROM products p JOIN shops s ON s.id = p.shop_id
        WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT 500";
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

// cheapest offer per set + type (among the rows shown and buyable)
$cheapest = [];
foreach ($rows as $r) {
    if ($r['price'] === null || !in_array($r['status'], ['preorder', 'available'], true) || !$r['set_code'] || $r['hidden']) continue;
    $k = $r['set_code'] . '|' . $r['product_type'];
    if (!isset($cheapest[$k]) || (float)$r['price'] < $cheapest[$k]) $cheapest[$k] = (float)$r['price'];
}

$rules = db()->query('SELECT * FROM alert_rules WHERE active = 1')->fetchAll();
$terms = search_terms();
$matchesRule = static function (array $p) use ($rules): ?array {
    foreach ($rules as $r) {
        if ($p['price'] === null || $p['hidden'] || !in_array($p['status'], ['preorder', 'available'], true)) return null;
        if ($r['product_type'] !== 'any' && $r['product_type'] !== $p['product_type']) continue;
        if ($r['country'] !== 'any' && $r['country'] !== $p['country']) continue;
        if ($r['statuses'] !== 'both' && $r['statuses'] !== $p['status']) continue;
        if (!text_matches($p['title'], $p['set_code'], $r['match_text'])) continue;
        if ((float)$p['price'] <= (float)$r['max_price']) return $r;
    }
    return null;
};

$labels = ['preorder' => 'Preorder', 'available' => 'In stock', 'sold_out' => 'Sold out', 'not_orderable' => 'Listed – not orderable'];
$titles = ['preorder' => 'Preorders', 'available' => 'Purchasable now', 'all' => 'All offers', 'hidden' => 'Hidden offers'];
$back = 'index.php?' . http_build_query(array_filter(['view' => $view, 'q' => $q, 'country' => $country, 'type' => $type, 'sort' => $sort, 'status' => $status]));
page_header($titles[$view], $view === 'hidden' ? 'all' : $view);
?>
<form class="filters" method="get">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search, e.g. OP-14">
  <select name="country"><option value="">DE + NL</option>
    <option value="DE" <?= $country === 'DE' ? 'selected' : '' ?>>Germany</option>
    <option value="NL" <?= $country === 'NL' ? 'selected' : '' ?>>Netherlands</option></select>
  <select name="type"><option value="">All types</option>
    <option value="display" <?= $type === 'display' ? 'selected' : '' ?>>Displays</option>
    <option value="case" <?= $type === 'case' ? 'selected' : '' ?>>Cases</option>
    <option value="special" <?= $type === 'special' ? 'selected' : '' ?>>Special products</option></select>
  <select name="sort">
    <option value="set" <?= $sort === 'set' ? 'selected' : '' ?>>Sort: set</option>
    <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Sort: price</option>
    <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Sort: newest</option></select>
  <?php if ($view === 'all'): ?>
  <select name="status"><option value="">Any status</option>
    <?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <button>Filter</button>
</form>
<?php if ($terms): ?>
<nav class="chips" aria-label="Search terms">
  <?php foreach ($terms as $t): ?>
    <a href="index.php?<?= h(http_build_query(['view' => $view, 'q' => $t, 'country' => $country, 'type' => $type, 'sort' => $sort])) ?>"
       class="<?= strcasecmp($q, $t) === 0 ? 'on' : '' ?>" title="<?= h($t) ?>"><?= h(term_label($t)) ?></a>
  <?php endforeach; ?>
  <?php if ($q !== ''): ?><a href="index.php?view=<?= h($view) ?>" class="clear">× clear</a><?php endif; ?>
</nav>
<?php endif; ?>

<h1><?= h($titles[$view]) ?> <span class="count"><?= count($rows) ?></span>
  <?php if ($view !== 'hidden'): ?><a class="small muted hidden-link" href="index.php?view=hidden">Hidden offers →</a><?php endif; ?></h1>
<?php if ($view === 'hidden'): ?><p class="muted">Offers you marked as not orderable. They are excluded from all lists and alerts until you show them again.</p><?php endif; ?>

<?php if (!$rows): ?>
  <p class="empty">Nothing here yet<?= $q || $country || $type ? ' for this filter' : '' ?>.</p>
<?php else: ?>
<div class="offers">
<?php foreach ($rows as $r):
    $rule = $matchesRule($r);
    $isCheapest = $r['set_code'] && $r['price'] !== null && in_array($r['status'], ['preorder', 'available'], true)
        && abs(($cheapest[$r['set_code'] . '|' . $r['product_type']] ?? -1) - (float)$r['price']) < 0.001;
    $prev = $r['prev_price'] !== null ? (float)$r['prev_price'] : null;
    $term = matched_term($r['title'], $r['set_code'], $terms);
?>
  <article class="offer <?= $rule ? 'hit' : '' ?> st-<?= h($r['status']) ?>">
    <a class="thumb" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer">
      <?php if ($r['image']): ?><img src="<?= h($r['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
    </a>
    <div class="info">
      <a class="title" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['title']) ?></a>
      <div class="meta">
        <span class="flag"><?= h($r['country']) ?></span> <?= h($r['shop_name']) ?>
        <?php if ($r['set_code']): ?>· <b><?= h($r['set_code']) ?></b><?php endif; ?>
        · <?= h(ucfirst($r['product_type'])) ?>
        · since <?= h(date('d.m.', strtotime($r['first_seen']))) ?>
        · <a href="history.php?id=<?= (int)$r['id'] ?>">history</a>
        · <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="back" value="<?= h($back) ?>">
            <?php if ($r['hidden']): ?><button name="action" value="show" class="link">show again</button>
            <?php else: ?><button name="action" value="hide" class="link" title="Can't actually be bought – hide it">not orderable</button><?php endif; ?></form>
      </div>
      <div class="tags">
        <span class="badge <?= h($r['status']) ?>"><?= h($labels[$r['status']] ?? $r['status']) ?></span>
        <?php if ($term): ?><span class="badge term">★ <?= h(range_codes($term) && $r['set_code'] ? $r['set_code'] : $term) ?></span><?php endif; ?>
        <?php if ($isCheapest): ?><span class="badge best">Cheapest</span><?php endif; ?>
        <?php if ($rule): ?><span class="badge alarm">≤ <?= h(eur($rule['max_price'])) ?> · <?= h($rule['label']) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="price">
      <?= h(eur($r['price'])) ?>
      <?php if ($prev !== null && $r['price'] !== null): ?>
        <small class="<?= (float)$r['price'] < $prev ? 'down' : 'up' ?>"><?= (float)$r['price'] < $prev ? '▼' : '▲' ?> <?= h(eur($prev)) ?></small>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif;
page_footer();
