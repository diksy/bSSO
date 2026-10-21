<?php

declare(strict_types=1);

// Buat superadmin pertama: php bin/create-admin.php --email=admin@contoh.id [--name="Nama"]
// Kata sandi dibaca dari prompt tersembunyi, atau variabel lingkungan BSSO_ADMIN_PASSWORD
// (jangan taruh kata sandi di argumen baris perintah; terlihat di daftar proses). Aktifkan MFA setelah login pertama.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

use App\Account\AdminCreator;

$container = require __DIR__ . '/../bootstrap.php';

$opts = getopt('', ['email:', 'name::']);
$email = is_string($opts['email'] ?? null) ? $opts['email'] : '';
$name = is_string($opts['name'] ?? null) ? $opts['name'] : 'Superadmin';
if ($email === '') {
    fwrite(STDERR, "Pemakaian: php bin/create-admin.php --email=admin@contoh.id [--name=\"Nama\"]\n");
    exit(2);
}

$password = getenv('BSSO_ADMIN_PASSWORD');
if (!is_string($password) || $password === '') {
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    fwrite(STDERR, 'Kata sandi (minimal 12 karakter): ');
    if ($tty) {
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
    }
    fwrite(STDERR, "\n");
}

try {
    $id = $container->make(AdminCreator::class)->create($email, $name, $password);
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal membuat admin (cek koneksi database dan migrasi): ' . $e::class . "\n");
    exit(1);
}

fwrite(STDOUT, "Superadmin dibuat (id $id). Masuk lalu aktifkan MFA di /account/mfa.\n");
