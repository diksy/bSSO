<?php
declare(strict_types=1);

$seed = [
    ['admin@contoh.test', 'client.update', 'portal-pegawai', ['before' => ['access_ttl' => 300], 'after' => ['access_ttl' => 600]]],
    ['budi@contoh.test', 'login.success', 'budi@contoh.test', []],
    ['citra@contoh.test', 'login.failed', 'citra@contoh.test', ['alasan' => 'password salah']],
    ['admin@contoh.test', 'key.rotate', 'k-2026-10-demo-a1', ['before' => ['next' => null], 'after' => ['next' => 'k-2026-10-demo-a1']]],
    ['operator@contoh.test', 'user.suspend', 'eko@contoh.test', ['before' => ['status' => 'aktif'], 'after' => ['status' => 'suspend']]],
    ['admin@contoh.test', 'settings.update', 'password_min', ['before' => ['password_min' => 10], 'after' => ['password_min' => 12]]],
    ['budi@contoh.test', 'token.reuse_detected', 'aplikasi-mobile', ['catatan' => 'refresh token dipakai ulang; keluarga token dicabut']],
    ['admin@contoh.test', 'provider.update', 'github', ['before' => ['active' => false], 'after' => ['active' => true]]],
    ['auditor@contoh.test', 'audit.export', 'csv', []],
    ['operator@contoh.test', 'maintenance.gc', 'gc', ['dihapus' => 128]],
    ['citra@contoh.test', 'mfa.enable', 'citra@contoh.test', []],
    ['admin@contoh.test', 'role.grant', 'tim@contoh.test', ['before' => ['role' => null], 'after' => ['role' => 'operator']]],
];
$out = [];
for ($i = 0; $i < 36; $i++) {
    $s = $seed[$i % 12];
    $ts = mktime(9, 0, 0, 10, 21, 2026) - $i * 5400;
    $out[] = ['id' => 1000 - $i, 'time' => date('Y-m-d H:i', $ts), 'actor' => $s[0], 'event' => $s[1], 'target' => $s[2], 'context' => $s[3]];
}
return $out;
