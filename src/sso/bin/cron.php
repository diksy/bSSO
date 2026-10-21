<?php

declare(strict_types=1);

// GC, retry notifikasi logout, rotasi kunci terjadwal. Jalankan tiap menit dari cron:
//   * * * * * php /path/ke/sso/bin/cron.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/** @var \App\Support\Container $container */
$container = require __DIR__ . '/../bootstrap.php';

// Cegah dua cron berjalan bersamaan.
$lockDir = dirname(__DIR__) . '/storage';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0700, true);
}
$lock = fopen($lockDir . '/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "cron sedang berjalan\n");
    exit(0);
}

$runner = $container->get(\App\Support\CronRunner::class);
foreach ($runner->run() as $task => $summary) {
    fwrite(STDOUT, $task . ': ' . $summary . "\n");
}
flock($lock, LOCK_UN);
