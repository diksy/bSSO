<?php

declare(strict_types=1);

// OPSIONAL: lebih mudah memakai ../.env (HMAC_KEY, ENCRYPTION_KEY_CURRENT, ENCRYPTION_KEYS); .env/env server menang.
// Jika dipakai, salin ke config/secrets.php (tidak di-commit, chmod 600). Isi dengan nilai acak
// (mis. base64 dari 32 byte random_bytes) yang dibuat di server; jangan simpan nilai asli di repo.
return [
    'hmac_key' => '',
    'keyring' => ['current' => 'v1', 'keys' => ['v1' => '']],
];
