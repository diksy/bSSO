<?php

declare(strict_types=1);

// Salin ke config/secrets.php (tidak di-commit, chmod 600). Isi dengan nilai acak
// (mis. base64 dari 32 byte random_bytes) yang dibuat di server; jangan simpan nilai asli di repo.
return [
    'hmac_key' => '',
    'keyring' => ['current' => 'v1', 'keys' => ['v1' => '']],
];
