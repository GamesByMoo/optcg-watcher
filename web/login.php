<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
start_session();

$error = null;
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?');
    $st->execute([$ip, date('Y-m-d H:i:s', time() - 900)]);
    if ((int)$st->fetchColumn() >= 5) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif (ADMIN_PASSWORD_HASH !== '' && password_verify((string)($_POST['password'] ?? ''), ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['seen'] = time();
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        header('Location: index.php');
        exit;
    } else {
        db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([$ip, now()]);
        usleep(800000);
        $error = 'Wrong password.';
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Login – OPTCG Watcher</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="login"><main class="narrow">
  <form method="post" class="card login-card">
    <h1>OPTCG Watcher</h1>
    <?php if ($error): ?><p class="flash err"><?= h($error) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <label>Password <input type="password" name="password" required autofocus autocomplete="current-password"></label>
    <button>Log in</button>
  </form>
</main></body></html>
