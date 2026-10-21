<?php
declare(strict_types=1);

// Data dummy. Semua alamat memakai domain .test (tidak nyata).
return [
    1 => ['id' => 1, 'name' => 'Sari Anggraini', 'email' => 'admin@contoh.test', 'status' => 'aktif', 'mfa' => true, 'admin_role' => 'superadmin', 'identities' => ['google'], 'last_login' => '2026-10-21 08:12', 'created' => '2025-01-10'],
    2 => ['id' => 2, 'name' => 'Dimas Prakoso', 'email' => 'operator@contoh.test', 'status' => 'aktif', 'mfa' => true, 'admin_role' => 'operator', 'identities' => [], 'last_login' => '2026-10-20 17:40', 'created' => '2025-03-02'],
    3 => ['id' => 3, 'name' => 'Wulan Maharani', 'email' => 'auditor@contoh.test', 'status' => 'aktif', 'mfa' => true, 'admin_role' => 'auditor', 'identities' => ['microsoft'], 'last_login' => '2026-10-19 09:05', 'created' => '2025-04-18'],
    4 => ['id' => 4, 'name' => 'Budi Santoso', 'email' => 'budi@contoh.test', 'status' => 'aktif', 'mfa' => true, 'admin_role' => null, 'identities' => ['google', 'github'], 'last_login' => '2026-10-21 07:30', 'created' => '2025-05-21'],
    5 => ['id' => 5, 'name' => 'Citra Lestari', 'email' => 'citra@contoh.test', 'status' => 'aktif', 'mfa' => false, 'admin_role' => null, 'identities' => [], 'last_login' => '2026-10-18 13:22', 'created' => '2025-06-30'],
    6 => ['id' => 6, 'name' => 'Eko Wibowo', 'email' => 'eko@contoh.test', 'status' => 'suspend', 'mfa' => false, 'admin_role' => null, 'identities' => ['gitlab'], 'last_login' => '2026-09-02 10:01', 'created' => '2025-07-12'],
    7 => ['id' => 7, 'name' => 'Tim Baru', 'email' => 'tim@contoh.test', 'status' => 'aktif', 'mfa' => false, 'admin_role' => 'operator', 'identities' => [], 'last_login' => '2026-10-15 11:11', 'created' => '2026-10-01'],
];
