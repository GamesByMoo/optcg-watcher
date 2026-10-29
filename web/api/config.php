<?php
// GET /api/config.php  -> active shops + settings for the scraper (token protected)
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
api_auth();

$shops = db()->query('SELECT id, name, country, platform, base_url, config FROM shops WHERE active = 1 ORDER BY id')->fetchAll();
foreach ($shops as &$s) {
    $s['id'] = (int)$s['id'];
    $cfg = json_decode((string)$s['config'], true);
    $s['config'] = is_array($cfg) && $cfg ? $cfg : new stdClass();
}
unset($s);

json_out([
    'shops' => $shops,
    'search_terms' => search_terms(),
    'product_types' => array_values(array_filter(explode(',', setting('product_types', 'display,case,special')))),
]);
