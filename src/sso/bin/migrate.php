<?php

declare(strict_types=1);

// Jalankan database/migrations berurutan (idempoten; dicatat di tabel schema_migrations),
// lalu seed provider bila tabel identity_providers kosong. Pemakaian: php bin/migrate.php
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @var App\Support\Container $container */
$container = require __DIR__ . '/../bootstrap.php';
/** @var Database $db */
$db = $container->get(Database::class);
$pdo = $db->pdo();

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $name = basename($file);
    $done = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE filename = ?');
    $done->execute([$name]);
    if ($done->fetchColumn() !== false) {
        echo "lewati  $name\n";
        continue;
    }
    // DDL MariaDB tidak transaksional; satu pernyataan per exec.
    foreach (explode(";\n", (string) file_get_contents($file)) as $statement) {
        $statement = rtrim(trim($statement), ';');
        if (trim((string) preg_replace('/^\s*--.*$/m', '', $statement)) === '') {
            continue;
        }
        $pdo->exec($statement);
    }
    $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
    echo "terapkan $name\n";
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM identity_providers')->fetchColumn();
if ($count === 0) {
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/seeds/providers.sql'));
    echo "seed    providers.sql\n";
}
echo "selesai\n";
