<?php

declare(strict_types=1);

// Rotasi kunci: php bin/rotate-key.php [--emergency]. Tanpa opsi: rotasi terjadwal idempoten (cron harian).
// Tidak mencetak kid/kunci ke output selain ringkasan aksi.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

use App\Security\KeyManager;
use App\Support\Audit;

$container = require __DIR__ . '/../bootstrap.php';

$opts = getopt('', ['emergency']);
$keys = $container->get(KeyManager::class);

try {
    if (isset($opts['emergency'])) {
        $result = $keys->rotate(true);
        fwrite(STDOUT, "Rotasi darurat selesai. Kunci lama dicabut; semua access token lama ditolak.\n");
    } else {
        $result = $keys->rotateIfDue();
        if ($result === null) {
            fwrite(STDOUT, "Belum jatuh tempo; tidak ada perubahan.\n");
            exit(0);
        }
        $container->get(Audit::class)->record('key.rotate_scheduled', null, null, null, ['actions' => $result->actions]);
        fwrite(STDOUT, 'Rotasi selesai: ' . implode(', ', $result->actions) . "\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Rotasi gagal: ' . get_class($e) . "\n");
    exit(1);
}
exit(0);
