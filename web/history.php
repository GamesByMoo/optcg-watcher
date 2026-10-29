<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();

$st = db()->prepare('SELECT p.*, s.name AS shop_name, s.country FROM products p JOIN shops s ON s.id = p.shop_id WHERE p.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$p = $st->fetch();
if (!$p) {
    http_response_code(404);
    exit('Not found');
}
$h = db()->prepare('SELECT * FROM price_history WHERE product_id = ? ORDER BY recorded_at DESC LIMIT 200');
$h->execute([(int)$p['id']]);
$hist = $h->fetchAll();

// same set + type in other shops
$others = [];
if ($p['set_code']) {
    $o = db()->prepare("SELECT p.*, s.name AS shop_name, s.country FROM products p JOIN shops s ON s.id = p.shop_id
                        WHERE p.active = 1 AND p.set_code = ? AND p.product_type = ? AND p.id <> ? ORDER BY p.price IS NULL, p.price");
    $o->execute([$p['set_code'], $p['product_type'], $p['id']]);
    $others = $o->fetchAll();
}

page_header('History', 'all');
?>
<p><a href="javascript:history.back()">← back</a></p>
<h1><?= h($p['title']) ?></h1>
<p class="meta"><?= h($p['shop_name']) ?> (<?= h($p['country']) ?>) · now <b><?= h(eur($p['price'])) ?></b> · <?= h($p['status']) ?>
  · <a href="<?= h($p['url']) ?>" target="_blank" rel="noopener noreferrer">open in shop ↗</a></p>

<h2>Price history</h2>
<table class="grid">
  <tr><th>When</th><th>Status</th><th class="r">Price</th></tr>
  <?php foreach ($hist as $row): ?>
    <tr><td><?= h(date('d.m.Y H:i', strtotime($row['recorded_at']))) ?></td><td><?= h($row['status']) ?></td><td class="r"><?= h(eur($row['price'])) ?></td></tr>
  <?php endforeach; ?>
</table>

<?php if ($others): ?>
<h2><?= h($p['set_code']) ?> in other shops</h2>
<table class="grid">
  <tr><th>Shop</th><th>Status</th><th class="r">Price</th></tr>
  <?php foreach ($others as $o): ?>
    <tr><td><a href="<?= h($o['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($o['shop_name']) ?> (<?= h($o['country']) ?>)</a></td>
        <td><?= h($o['status']) ?></td><td class="r"><?= h(eur($o['price'])) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif;
page_footer();
