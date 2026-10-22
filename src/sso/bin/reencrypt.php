<?php

declare(strict_types=1);

// Enkripsi ulang data ke versi keyring 'current'. Pemakaian: php bin/reencrypt.php [--dry-run]
// Output hanya hitungan dan nama tabel/kolom/id; exit 1 bila ada baris gagal.
use App\Security\Crypto;
use App\Security\Reencryptor;
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @var App\Support\Container $container */
$container = require __DIR__ . '/../bootstrap.php';
$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);

$tool = new Reencryptor($container->get(Database::class), $container->get(Crypto::class));
$r = $tool->run($dryRun);
foreach ($r['errors'] as $e) {
    fwrite(STDERR, $e . "\n");
}
echo ($dryRun ? 'dry-run: ' : '') . "diubah={$r['updated']} dilewati={$r['skipped']} gagal={$r['failed']}\n";
exit($r['failed'] > 0 ? 1 : 0);
