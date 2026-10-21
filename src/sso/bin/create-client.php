<?php

declare(strict_types=1);

// Buat klien OAuth. Contoh:
//   php bin/create-client.php --name="Aplikasi A" --type=confidential --redirect-uri=https://a.example/cb \
//     [--redirect-uri=...] [--post-logout-uri=...] [--grants=authorization_code,refresh_token] \
//     [--scopes="openid profile email offline_access"] [--backchannel-logout-uri=https://a.example/bc]
// client_secret ditampilkan SEKALI di stdout; DB hanya menyimpan hash-nya.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

use App\OAuth\ClientCreator;

$container = require __DIR__ . '/../bootstrap.php';

$opts = getopt('', ['name:', 'type::', 'redirect-uri:', 'post-logout-uri::', 'grants::', 'scopes::', 'backchannel-logout-uri::']);
$list = static fn (mixed $v): array => $v === null || $v === false ? [] : array_values(array_filter((array) $v, 'is_string'));
$one = static fn (mixed $v): ?string => is_string($v) ? $v : null;

if ($one($opts['name'] ?? null) === null || $list($opts['redirect-uri'] ?? null) === []) {
    fwrite(STDERR, "Pemakaian: php bin/create-client.php --name=\"Nama\" [--type=confidential|public] --redirect-uri=https://app/cb [...]\n");
    exit(2);
}

try {
    $grants = $one($opts['grants'] ?? null);
    $result = $container->make(ClientCreator::class)->create(
        (string) $one($opts['name'] ?? null),
        $one($opts['type'] ?? null) ?? 'confidential',
        $list($opts['redirect-uri'] ?? null),
        $list($opts['post-logout-uri'] ?? null),
        $grants === null ? null : array_map('trim', explode(',', $grants)),
        $one($opts['scopes'] ?? null) ?? 'openid profile email offline_access',
        $one($opts['backchannel-logout-uri'] ?? null),
    );
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal membuat klien (cek koneksi database dan migrasi): ' . $e::class . "\n");
    exit(1);
}

fwrite(STDOUT, 'client_id: ' . $result['client']->clientId . "\n");
if ($result['secret'] !== null) {
    fwrite(STDOUT, 'client_secret: ' . $result['secret'] . "\n(simpan sekarang; tidak akan ditampilkan lagi)\n");
} else {
    fwrite(STDOUT, "Public client: tanpa secret (wajib PKCE S256).\n");
}
