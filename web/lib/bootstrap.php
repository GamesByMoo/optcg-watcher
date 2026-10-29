<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    exit('config.php is missing. Copy config.sample.php to config.php and fill it in, then open install.php.');
}
require __DIR__ . '/../config.php';
date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function eur($v): string
{
    return $v === null ? '–' : number_format((float)$v, 2, ',', '.') . ' €';
}

function setting(string $k, string $default = ''): string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? $default : (string)$v;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$k, $v]);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// ------------------------------------------------------------ sessions/auth
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('optcg');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function require_login(): void
{
    start_session();
    $idle = 60 * 60 * 12; // log out after 12 h of inactivity
    if (empty($_SESSION['auth']) || (time() - ($_SESSION['seen'] ?? 0)) > $idle) {
        $_SESSION = [];
        header('Location: login.php');
        exit;
    }
    $_SESSION['seen'] = time();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid form token. Please reload the page.');
    }
}

function flash(?string $msg = null): ?string
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

// ------------------------------------------------------------------- API
function api_auth(): void
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $hdr = $v;
            }
        }
    }
    $token = preg_replace('/^Bearer\s+/i', '', $hdr);
    if (API_TOKEN === '' || strlen(API_TOKEN) < 32 || !hash_equals(API_TOKEN, (string)$token)) {
        json_out(['error' => 'unauthorized'], 401);
    }
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------ countries
const COUNTRIES = ['DE' => 'Germany', 'NL' => 'Netherlands / Belgium', 'AT' => 'Austria'];

/** <option> tags for a country <select>; $anyLabel adds a first "all countries" option. */
function country_options(string $selected, ?string $anyLabel = null, string $anyValue = ''): string
{
    $o = $anyLabel !== null ? '<option value="' . h($anyValue) . '">' . h($anyLabel) . '</option>' : '';
    foreach (COUNTRIES as $k => $l) {
        $o .= '<option value="' . $k . '"' . ($selected === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    return $o;
}

// ------------------------------------------------------------ search terms
const DEFAULT_SEARCH_TERMS = "EB05\nEB06\nEB07\nEB08\nOP-19\nOP-20\nOP-21\nOP-22\nOP-23\nOP-PEB\nOP-PEB01\nST37-ST50";
const SET_CODE_RE = '(OP|EB|PRB|PEB|ST|DP|LD)\s*-?\s*(\d{1,2})';

/** The user's search terms (Settings -> Search terms), one per entry. */
function search_terms(): array
{
    $raw = setting('search_terms', DEFAULT_SEARCH_TERMS);
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw)))));
}

function compact_text(?string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string)$s));
}

/** 'OP-PEB' -> 'PEB': an OP- prefix in front of letters is not part of the code. */
function term_core(string $t): string
{
    return preg_replace('/^op\s*-?\s*(?=[a-z])/i', '', trim($t));
}

/** 'eb5', 'EB05', 'EB-05' -> 'EB-05'; null if the text is not exactly a set code. */
function canonical_code(string $t): ?string
{
    return preg_match('/^' . SET_CODE_RE . '$/i', trim(term_core($t)), $m)
        ? strtoupper($m[1]) . '-' . str_pad((string)(int)$m[2], 2, '0', STR_PAD_LEFT) : null;
}

/** 'ST37-ST50' / 'ST37..50' -> ['ST-37', ..., 'ST-50']; null if the term is not a range. */
function range_codes(string $t): ?array
{
    if (!preg_match('/^\s*' . SET_CODE_RE . '\s*(?:\.\.|–|—|-|bis|to)\s*(?:\1\s*-?\s*)?(\d{1,2})\s*$/iu', $t, $m)) return null;
    [$a, $b] = [min((int)$m[2], (int)$m[3]), max((int)$m[2], (int)$m[3])];
    $codes = [];
    for ($n = $a; $n <= min($b, $a + 39); $n++) $codes[] = strtoupper($m[1]) . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    return $codes;
}

/** Short label for a term chip: 'ST37-ST50' -> 'ST 37–50'. */
function term_label(string $t): string
{
    $rc = range_codes($t);
    return $rc ? explode('-', $rc[0])[0] . ' ' . substr($rc[0], -2) . '–' . substr(end($rc), -2) : $t;
}

/** Does a product (title + set code) match a term? 'EB05' matches 'EB-05' and 'EB 5'. */
function text_matches(string $title, ?string $setCode, string $term): bool
{
    $term = trim($term);
    if ($term === '') return true;
    if ($rc = range_codes($term)) {
        foreach ($rc as $code) {
            if (text_matches($title, $setCode, $code)) return true;
        }
        return false;
    }
    if ($code = canonical_code($term)) {
        if ($setCode !== null && strcasecmp($setCode, $code) === 0) return true;
        preg_match_all('/\b' . SET_CODE_RE . '\b/i', $title, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            if (strtoupper($m[1]) . '-' . str_pad((string)(int)$m[2], 2, '0', STR_PAD_LEFT) === $code) return true;
        }
        return false; // codes match only as codes, so OP-2 never matches OP-20
    }
    $needle = compact_text(term_core($term));
    return $needle !== '' && strpos(compact_text($title . ' ' . $setCode), $needle) !== false;
}

/** First of the user's search terms that a product matches, or null. */
function matched_term(string $title, ?string $setCode, array $terms): ?string
{
    foreach ($terms as $t) {
        if (text_matches($title, $setCode, $t)) return $t;
    }
    return null;
}
