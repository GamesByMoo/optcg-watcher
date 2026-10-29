<?php
// POST /api/ingest.php  <- scraper results from GitHub Actions (token protected)
// Stores offers, keeps a price history, evaluates alert rules and sends one e-mail.
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/mail.php';
api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['shops']) || !is_array($payload['shops'])) {
    json_out(['error' => 'invalid payload'], 400);
}

$pdo = db();
$now = now();
$toLocal = static function ($iso) use ($now): string {
    $t = is_string($iso) ? strtotime($iso) : false;
    return $t ? date('Y-m-d H:i:s', $t) : $now;
};
$allowedTypes = ['display', 'case', 'special'];
$allowedStatus = ['preorder', 'available', 'sold_out', 'not_orderable'];

$sel = $pdo->prepare('SELECT id, price, status FROM products WHERE shop_id = ? AND external_id = ?');
$ins = $pdo->prepare('INSERT INTO products (shop_id, external_id, title, url, image, set_code, product_type, status, price, currency, first_seen, last_seen, active)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)');
$upd = $pdo->prepare('UPDATE products SET title=?, url=?, image=?, set_code=?, product_type=?, status=?, price=?, currency=?, last_seen=?, active=1 WHERE id=?');
$resetAlert = $pdo->prepare('UPDATE products SET last_alert_price = NULL WHERE id = ?');
$hist = $pdo->prepare('INSERT INTO price_history (product_id, price, status, recorded_at) VALUES (?,?,?,?)');
$shopUpd = $pdo->prepare('UPDATE shops SET last_run_at=?, last_ok_at=IF(?, ?, last_ok_at), last_error=?, last_raw_count=?, last_item_count=?,
                          fail_streak = IF(?, 0, fail_streak + 1) WHERE id=?');
$shopDetected = $pdo->prepare('UPDATE shops SET platform=?, config=? WHERE id=?');
$platforms = ['shopify', 'woocommerce', 'jsonld', 'html'];

$shopsOk = $shopsFailed = $shopsSkipped = $itemCount = 0;
$messages = [];

foreach ($payload['shops'] as $res) {
    $shopId = (int)($res['shop_id'] ?? 0);
    if ($shopId <= 0) {
        continue;
    }
    // platform auto-detection result -> remember it, so detection runs only once
    $det = $res['detected'] ?? null;
    if (is_array($det) && in_array($det['platform'] ?? '', $platforms, true) && is_array($det['config'] ?? null)) {
        $shopDetected->execute([$det['platform'], json_encode($det['config'] ?: new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $shopId]);
    }
    if (!empty($res['skipped'])) { // ran out of time this run - not the shop's fault
        $shopsSkipped++;
        continue;
    }
    $ok = !empty($res['ok']);
    $items = is_array($res['items'] ?? null) ? $res['items'] : [];
    $error = $ok ? null : mb_substr((string)($res['error'] ?? 'unknown error'), 0, 1000);
    $shopUpd->execute([$now, $ok ? 1 : 0, $now, $error, (int)($res['raw_count'] ?? 0), count($items), $ok ? 1 : 0, $shopId]);

    if (!$ok) {
        $shopsFailed++;
        continue;
    }
    $shopsOk++;

    $pdo->beginTransaction();
    $seenIds = [];
    foreach ($items as $it) {
        $ext = mb_substr((string)($it['external_id'] ?? ''), 0, 255);
        $title = mb_substr(trim((string)($it['title'] ?? '')), 0, 500);
        $url = (string)($it['url'] ?? '');
        if ($ext === '' || $title === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $type = in_array($it['product_type'] ?? '', $allowedTypes, true) ? $it['product_type'] : 'special';
        $status = in_array($it['status'] ?? '', $allowedStatus, true) ? $it['status'] : 'not_orderable';
        $price = isset($it['price']) && is_numeric($it['price']) ? round((float)$it['price'], 2) : null;
        $image = isset($it['image']) && preg_match('#^(https?:)?//#i', (string)$it['image']) ? mb_substr((string)$it['image'], 0, 1000) : null;
        if ($image !== null && strpos($image, '//') === 0) {
            $image = 'https:' . $image;
        }
        $set = isset($it['set_code']) ? mb_substr((string)$it['set_code'], 0, 10) : null;
        $cur = strtoupper(mb_substr((string)($it['currency'] ?? 'EUR'), 0, 3));

        $sel->execute([$shopId, $ext]);
        $row = $sel->fetch();
        if (!$row) {
            $ins->execute([$shopId, $ext, $title, mb_substr($url, 0, 1000), $image, $set, $type, $status, $price, $cur, $now, $now]);
            $pid = (int)$pdo->lastInsertId();
            $hist->execute([$pid, $price, $status, $now]);
        } else {
            $pid = (int)$row['id'];
            $upd->execute([$title, mb_substr($url, 0, 1000), $image, $set, $type, $status, $price, $cur, $now, $pid]);
            $priceChanged = ($row['price'] === null) !== ($price === null)
                || ($price !== null && abs((float)$row['price'] - $price) > 0.001);
            if ($priceChanged || $row['status'] !== $status) {
                $hist->execute([$pid, $price, $status, $now]);
            }
            // back in stock / reopened for preorder -> allow a fresh alert
            if (in_array($row['status'], ['sold_out', 'not_orderable'], true) && in_array($status, ['preorder', 'available'], true)) {
                $resetAlert->execute([$pid]);
            }
        }
        $seenIds[] = $pid;
        $itemCount++;
    }
    // Offers that vanished from a shop are hidden. If a shop suddenly returns
    // nothing (layout change, bot block) we keep the old data instead.
    if ($seenIds) {
        $in = implode(',', array_map('intval', $seenIds));
        $pdo->prepare("UPDATE products SET active = 0 WHERE shop_id = ? AND active = 1 AND id NOT IN ($in)")->execute([$shopId]);
    }
    $pdo->commit();
}

// Shops that failed 20 runs in a row (~15 hours) are paused so they don't waste run time.
$paused = $pdo->query('SELECT name FROM shops WHERE active = 1 AND fail_streak >= 20')->fetchAll(PDO::FETCH_COLUMN);
if ($paused) {
    $pdo->exec('UPDATE shops SET active = 0 WHERE active = 1 AND fail_streak >= 20');
    $messages[] = 'Auto-paused after 20 failed checks: ' . implode(', ', $paused);
}
if ($shopsSkipped) {
    $messages[] = "$shopsSkipped shop(s) skipped: time budget used up - raise OPT_WORKERS in the workflow";
}

// ------------------------------------------------------------------ alerts
$rules = $pdo->query('SELECT * FROM alert_rules WHERE active = 1')->fetchAll();
$alerts = [];
if ($rules) {
    $products = $pdo->query("SELECT p.*, s.name AS shop_name, s.country
                             FROM products p JOIN shops s ON s.id = p.shop_id
                             WHERE p.active = 1 AND p.hidden = 0 AND p.price IS NOT NULL AND p.status IN ('preorder','available')")->fetchAll();
    foreach ($products as $p) {
        $best = null;
        foreach ($rules as $r) {
            if ($r['product_type'] !== 'any' && $r['product_type'] !== $p['product_type']) continue;
            if ($r['country'] !== 'any' && $r['country'] !== $p['country']) continue;
            if ($r['statuses'] !== 'both' && $r['statuses'] !== $p['status']) continue;
            if (!text_matches($p['title'], $p['set_code'], $r['match_text'])) continue;
            if ((float)$p['price'] > (float)$r['max_price']) continue;
            if ($best === null || (float)$r['max_price'] < (float)$best['max_price']) {
                $best = $r;
            }
        }
        if ($best === null) continue;
        // only alert again if the price dropped below the last alerted price
        if ($p['last_alert_price'] !== null && (float)$p['price'] >= (float)$p['last_alert_price']) continue;
        $alerts[] = [
            'id' => (int)$p['id'], 'title' => $p['title'], 'url' => $p['url'], 'shop' => $p['shop_name'],
            'country' => $p['country'], 'status' => $p['status'], 'price' => (float)$p['price'],
            'old_price' => $p['last_alert_price'] !== null ? (float)$p['last_alert_price'] : null,
            'rule' => $best['label'], 'max_price' => (float)$best['max_price'],
        ];
    }
}

$alertsSent = 0;
$to = setting('alert_email');
if ($alerts && $to !== '') {
    usort($alerts, static fn($a, $b) => $a['price'] <=> $b['price']);
    $subject = count($alerts) === 1
        ? 'OPTCG: ' . $alerts[0]['title'] . ' for ' . eur($alerts[0]['price'])
        : 'OPTCG: ' . count($alerts) . ' offers below your limit';
    if (send_mail($to, $subject, alert_mail_html($alerts))) {
        $mark = $pdo->prepare('UPDATE products SET last_alert_price = price WHERE id = ?');
        foreach ($alerts as $a) {
            $mark->execute([$a['id']]);
        }
        $alertsSent = count($alerts);
    } else {
        $messages[] = 'mail() failed - check MAIL_FROM in config.php';
    }
} elseif ($alerts) {
    $messages[] = count($alerts) . ' alert(s) pending, but no alert e-mail is configured';
}

$pdo->prepare('INSERT INTO runs (started_at, finished_at, shops_ok, shops_failed, items, alerts_sent, message) VALUES (?,?,?,?,?,?,?)')
    ->execute([$toLocal($payload['started_at'] ?? null), $now, $shopsOk, $shopsFailed, $itemCount, $alertsSent, $messages ? implode("\n", $messages) : null]);

// keep the runs table small
$pdo->exec('DELETE FROM runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
$pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');

json_out(['ok' => true, 'shops_ok' => $shopsOk, 'shops_failed' => $shopsFailed, 'items' => $itemCount, 'alerts_sent' => $alertsSent, 'notes' => $messages]);
