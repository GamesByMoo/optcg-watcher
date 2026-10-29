<?php
declare(strict_types=1);

const SHOP_PLATFORMS = [
    'auto' => 'Auto-detect',
    'shopify' => 'Shopify',
    'woocommerce' => 'WooCommerce',
    'jsonld' => 'Universal (search + schema.org)',
    'html' => 'Manual (CSS selectors)',
];

/** "shop.de/" -> "https://shop.de", keeps a path like /en. Returns null if not a URL. */
function shop_normalize_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $p = parse_url($url);
    if (!$p || empty($p['host']) || strpos($p['host'], '.') === false) return null;
    $path = rtrim($p['path'] ?? '', '/');
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
}

function shop_name_from_url(string $url): string
{
    $host = preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
    $name = preg_replace('/\.[a-z]{2,}$/', '', $host); // drop TLD
    return ucwords(str_replace(['-', '.'], ' ', $name));
}

function shop_country_from_url(string $url): string
{
    $host = (string)parse_url($url, PHP_URL_HOST);
    return preg_match('/\.(nl|be)$/', $host) ? 'NL' : 'DE';
}

/**
 * Parses lines like
 *   Shop Name | https://shop.de | DE
 *   https://shop.nl | NL
 *   shop.de
 * and inserts shops that are not in the list yet (platform: auto).
 * Returns [added, already_listed, invalid_lines].
 */
function shop_import(PDO $pdo, string $text): array
{
    $existing = [];
    foreach ($pdo->query('SELECT base_url FROM shops')->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $existing[preg_replace('#^https?://(www\.)?#', '', strtolower((string)$u))] = true;
    }
    $ins = $pdo->prepare("INSERT INTO shops (name, country, platform, base_url, config, active) VALUES (?, ?, 'auto', ?, '{}', 1)");
    $added = $dupes = 0;
    $invalid = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = array_map('trim', explode('|', $line));
        $url = $name = $country = null;
        foreach ($parts as $part) {
            if ($url === null && preg_match('#^(https?://)?[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?(/\S*)?$#i', $part)) {
                $url = shop_normalize_url($part);
            } elseif (preg_match('/^(DE|NL)$/i', $part)) {
                $country = strtoupper($part);
            } elseif ($name === null && $part !== '') {
                $name = mb_substr($part, 0, 100);
            }
        }
        if ($url === null) {
            $invalid[] = $line;
            continue;
        }
        $key = preg_replace('#^https?://(www\.)?#', '', $url);
        if (isset($existing[$key])) {
            $dupes++;
            continue;
        }
        $ins->execute([$name ?: shop_name_from_url($url), $country ?: shop_country_from_url($url), $url]);
        $existing[$key] = true;
        $added++;
    }
    return [$added, $dupes, $invalid];
}
