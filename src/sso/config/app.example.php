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
    // Proxy tepercaya (IP atau CIDR, IPv4/IPv6), mis. ['127.0.0.1', '10.0.0.0/8', '2400:cb00::/32'].
    // Kosong (default) = X-Forwarded-For diabaikan, IP klien = REMOTE_ADDR.
    'trusted_proxies' => [],
];
