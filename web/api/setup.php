<?php
// POST /api/setup.php  <- called by the deploy workflow after each upload (token protected).
// Creates or upgrades the database tables; safe to call repeatedly.
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/setup.php';
api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
try {
    $done = run_setup(db());
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database: ' . $e->getMessage()], 500);
}
json_out(['ok' => true, 'done' => $done]);
