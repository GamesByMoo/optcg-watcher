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

    if ((int)$pdo->query('SELECT COUNT(*) FROM shops')->fetchColumn() === 0) {
        $file = __DIR__ . '/../shops-de-nl.txt';
        [$added] = shop_import($pdo, is_file($file) ? (string)file_get_contents($file) : '');
        $done[] = "$added shops added from the starting list";
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM alert_rules')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO alert_rules (label, match_text, product_type, country, statuses, max_price) VALUES (?,?,?,?,?,?)')
            ->execute(['Any booster display under 100 €', '', 'display', 'any', 'preorder', 100]);
        $done[] = 'example price rule added';
    }
    return $done;
}
