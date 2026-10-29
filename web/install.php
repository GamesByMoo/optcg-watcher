<?php
// One-time setup helper. DELETE THIS FILE after setup is complete.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/setup.php';

$out = [];
$hash = null;
$token = bin2hex(random_bytes(32));

function tables_exist(): bool
{
    try {
        db()->query('SELECT 1 FROM shops LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$installed = ADMIN_PASSWORD_HASH !== '' && tables_exist();

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'hash') {
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($pw) < 10) {
            $out[] = ['err', 'Please use at least 10 characters.'];
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
        }
    } elseif ($action === 'tables') {
        try {
            $done = run_setup(db());
            $out[] = ['ok', 'Setup done: ' . implode(', ', $done) . '.'];
        } catch (Throwable $e) {
            $out[] = ['err', 'Database error: ' . $e->getMessage()];
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install – OPTCG Watcher</title><link rel="stylesheet" href="assets/style.css"></head>
<body><main class="narrow">
<h1>OPTCG Watcher setup</h1>
<?php foreach ($out as [$t, $m]): ?><p class="flash <?= $t ?>"><?= h($m) ?></p><?php endforeach; ?>

<?php if ($installed): ?>
  <p class="flash ok">Setup is complete. <b>Delete install.php from your webspace now.</b></p>
  <p><a href="login.php">Go to login</a></p>
<?php else: ?>
  <section class="card">
    <h2>1. Login password</h2>
    <form method="post">
      <input type="hidden" name="action" value="hash">
      <label>Choose a password <input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
      <button>Generate hash</button>
    </form>
    <?php if ($hash): ?>
      <p>Paste this into <code>config.php</code> as <code>ADMIN_PASSWORD_HASH</code>:</p>
      <pre class="copy"><?= h($hash) ?></pre>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>2. API token</h2>
    <p>Random token for this page load. Put it in <code>config.php</code> as <code>API_TOKEN</code>
       and as the GitHub secret <code>OPT_API_TOKEN</code>:</p>
    <pre class="copy"><?= h($token) ?></pre>
  </section>

  <section class="card">
    <h2>3. Database tables</h2>
    <p>Uses the database settings from <code>config.php</code>.</p>
    <form method="post"><input type="hidden" name="action" value="tables"><button>Create tables</button></form>
    <p class="muted">Status: <?= tables_exist() ? 'tables exist ✓' : 'not created yet' ?> ·
       Password hash: <?= ADMIN_PASSWORD_HASH !== '' ? 'set ✓' : 'not set' ?> ·
       API token: <?= strlen(API_TOKEN) >= 32 ? 'set ✓' : 'not set' ?></p>
  </section>
<?php endif; ?>
</main></body></html>
