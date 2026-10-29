<?php
declare(strict_types=1);

require_once __DIR__ . '/shops.php';

/**
 * Creates/updates all tables and adds the starting data. Safe to run any number of times
 * (used by api/setup.php after every deployment and by install.php).
 * Returns a list of what was done.
 */
function run_setup(PDO $pdo): array
{
    $done = [];
    $sql = (string)file_get_contents(__DIR__ . '/../schema.sql');
    $sql = preg_replace('/--[^\n]*/', '', $sql); // strip comments before splitting on ;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    $done[] = 'tables ok';

    // upgrades for databases created by older versions
    $cols = $pdo->query('SHOW COLUMNS FROM shops')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('fail_streak', $cols, true)) {
        $pdo->exec("ALTER TABLE shops MODIFY platform VARCHAR(20) NOT NULL DEFAULT 'auto', ADD fail_streak INT NOT NULL DEFAULT 0");
        $done[] = 'upgraded shops table';
    }

    $pcols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('hidden', $pcols, true)) {
        $pdo->exec('ALTER TABLE products MODIFY status VARCHAR(20) NOT NULL, ADD hidden TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE price_history MODIFY status VARCHAR(20) NOT NULL');
        $done[] = 'added not-orderable status and hide flag';
    }

    // one-time: add the starter deck range to an already saved search-term list
    $st = $pdo->query("SELECT v FROM settings WHERE k = 'search_terms'")->fetchColumn();
    $flag = $pdo->query("SELECT v FROM settings WHERE k = 'migr_st_range'")->fetchColumn();
    if ($flag === false) {
        if ($st !== false && $st !== null && stripos((string)$st, 'ST37') === false) {
            $pdo->prepare("UPDATE settings SET v = ? WHERE k = 'search_terms'")->execute([rtrim((string)$st) . "\nST37-ST50"]);
            $done[] = 'added ST37-ST50 to search terms';
        }
        $pdo->exec("INSERT INTO settings (k, v) VALUES ('migr_st_range', '1')");
    }

    // Starting shop list: new lines in shops-de-nl.txt are imported on the next deploy.
    // Remembered per domain, so shops you delete in the web app don't come back.
    $file = __DIR__ . '/../shops-de-nl.txt';
    if (is_file($file)) {
        $seenRaw = $pdo->query("SELECT v FROM settings WHERE k = 'seed_seen'")->fetchColumn();
        $seen = $seenRaw ? (array)json_decode((string)$seenRaw, true) : [];
        if ($seenRaw === false) { // first run of this mechanism: everything already in the DB counts as seen
            foreach ($pdo->query('SELECT base_url FROM shops')->fetchAll(PDO::FETCH_COLUMN) as $u) $seen[shop_key((string)$u)] = 1;
        }
        $new = [];
        foreach (preg_split('/\R/', (string)file_get_contents($file)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !preg_match('#(https?://)?([a-z0-9-]+\.)+[a-z]{2,}\S*#i', $line, $m)) continue;
            $key = shop_key(shop_normalize_url($m[0]) ?? $m[0]);
            if (!isset($seen[$key])) { $new[] = $line; $seen[$key] = 1; }
        }
        if ($new) {
            [$added] = shop_import($pdo, implode("\n", $new));
            $done[] = "$added new shop(s) added from the starting list";
        }
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('seed_seen', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute([json_encode($seen)]);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM alert_rules')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO alert_rules (label, match_text, product_type, country, statuses, max_price) VALUES (?,?,?,?,?,?)')
            ->execute(['Any booster display under 100 €', '', 'display', 'any', 'preorder', 100]);
        $done[] = 'example price rule added';
    }
    return $done;
}
