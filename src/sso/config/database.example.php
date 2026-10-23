<?php

declare(strict_types=1);

// OPSIONAL: lebih mudah memakai ../.env (DB_*); .env/env server menang atas file ini.
// Jika dipakai, salin ke config/database.php (tidak di-commit). Rahasia dari environment variable.
return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'bikinin_sso',
    'user' => getenv('DB_USER') ?: '',
    'pass' => getenv('DB_PASS') ?: '',
];
