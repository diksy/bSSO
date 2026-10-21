<?php

declare(strict_types=1);

// Salin ke config/app.php (tidak di-commit). Tanpa rahasia di sini.
return [
    'debug' => false,
    'mail_from' => '',
    // URL publik SSO (https://sso.contoh.id) untuk tautan di email; tidak diambil dari header Host.
    'base_url' => '',
    // Direktori private key RS256 (di luar webroot, izin 0700). Default: <sso>/keys
    'key_dir' => '',
];
