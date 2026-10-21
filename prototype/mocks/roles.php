<?php
declare(strict_types=1);

// Matriks hak akses (requirements §8). level: none | read | write
$r = static fn (string $level, string $label): array => ['level' => $level, 'label' => $label];

return [
    'roles' => ['superadmin' => 'Superadmin', 'operator' => 'Operator', 'auditor' => 'Auditor'],
    'modules' => [
        'dasbor' => 'Dasbor',
        'klien' => 'Aplikasi klien',
        'provider' => 'Provider',
        'user' => 'User & sesi',
        'kunci' => 'Kunci',
        'audit' => 'Audit log',
        'pemeliharaan' => 'Pemeliharaan',
        'pengaturan' => 'Pengaturan',
        'role' => 'Role admin',
    ],
    'matrix' => [
        'dasbor' => ['superadmin' => $r('read', 'Baca'), 'operator' => $r('read', 'Baca'), 'auditor' => $r('read', 'Baca')],
        'klien' => ['superadmin' => $r('write', 'Kelola'), 'operator' => $r('none', '–'), 'auditor' => $r('read', 'Baca')],
        'provider' => ['superadmin' => $r('write', 'Kelola'), 'operator' => $r('none', '–'), 'auditor' => $r('read', 'Baca')],
        'user' => ['superadmin' => $r('write', 'Kelola'), 'operator' => $r('write', 'Kelola (kecuali role admin)'), 'auditor' => $r('read', 'Baca')],
        'kunci' => ['superadmin' => $r('write', 'Kelola'), 'operator' => $r('none', '–'), 'auditor' => $r('read', 'Baca')],
        'audit' => ['superadmin' => $r('write', 'Baca + ekspor'), 'operator' => $r('read', 'Baca'), 'auditor' => $r('write', 'Baca + ekspor')],
        'pemeliharaan' => ['superadmin' => $r('write', 'Jalankan'), 'operator' => $r('write', 'Jalankan'), 'auditor' => $r('none', '–')],
        'pengaturan' => ['superadmin' => $r('write', 'Kelola'), 'operator' => $r('none', '–'), 'auditor' => $r('read', 'Baca')],
        'role' => ['superadmin' => $r('write', 'Kelola'), 'operator' => $r('none', '–'), 'auditor' => $r('none', '–')],
    ],
];
