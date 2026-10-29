<?php
declare(strict_types=1);

function page_header(string $title, string $active): void
{
    $nav = [
        'preorder' => ['index.php?view=preorder', 'Preorders'],
        'available' => ['index.php?view=available', 'Buy now'],
        'all' => ['index.php?view=all', 'All'],
        'settings' => ['settings.php', 'Settings'],
    ];
    $last = db()->query('SELECT * FROM runs ORDER BY id DESC LIMIT 1')->fetch();
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> – OPTCG Watcher</title><link rel="stylesheet" href="assets/style.css"></head>
<body>
<header class="top">
  <a class="brand" href="index.php">OPTCG Watcher</a>
  <nav>
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= h($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
    <a href="logout.php" class="logout">Log out</a>
  </nav>
</header>
<div class="runbar">
  <?php if ($last): ?>
    Last check: <b><?= h(date('d.m. H:i', strtotime($last['finished_at']))) ?></b>
    · <?= (int)$last['items'] ?> offers · <?= (int)$last['shops_ok'] ?> shops ok
    <?php if ((int)$last['shops_failed'] > 0): ?><span class="bad">· <?= (int)$last['shops_failed'] ?> failed</span><?php endif; ?>
    <?php if ((int)$last['alerts_sent'] > 0): ?>· <?= (int)$last['alerts_sent'] ?> alert(s) mailed<?php endif; ?>
    <?php if (strtotime($last['finished_at']) < time() - 3 * 3600): ?><span class="bad">· no run in the last 3 hours – check GitHub Actions</span><?php endif; ?>
  <?php else: ?>
    No check has run yet. Start the workflow in GitHub Actions.
  <?php endif; ?>
</div>
<main>
<?php if ($m = flash()): ?><p class="flash ok"><?= h($m) ?></p><?php endif;
}

function page_footer(): void
{
    echo "</main></body></html>";
}
