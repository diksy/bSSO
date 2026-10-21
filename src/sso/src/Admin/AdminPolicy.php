<?php

declare(strict_types=1);

namespace App\Admin;

/** Matriks hak akses requirements §8. */
final class AdminPolicy
{
    public const MATRIX = [
        'superadmin' => [
            'dashboard' => 'read', 'clients' => 'manage', 'providers' => 'manage', 'users' => 'manage',
            'keys' => 'manage', 'audit' => 'manage', 'maintenance' => 'manage', 'settings' => 'manage',
            'roles' => 'manage',
        ],
        'operator' => [
            'dashboard' => 'read', 'clients' => 'none', 'providers' => 'none', 'users' => 'manage',
            'keys' => 'none', 'audit' => 'read', 'maintenance' => 'manage', 'settings' => 'none',
            'roles' => 'none',
        ],
        'auditor' => [
            'dashboard' => 'read', 'clients' => 'read', 'providers' => 'read', 'users' => 'read',
            'keys' => 'read', 'audit' => 'manage', 'maintenance' => 'none', 'settings' => 'read',
            'roles' => 'none',
        ],
    ];

    /** Modul => [label, url] untuk navigasi. */
    public const MODULES = [
        'dashboard' => ['Dasbor', '/admin'],
        'clients' => ['Aplikasi klien', '/admin/clients'],
        'providers' => ['Provider', '/admin/providers'],
        'users' => ['User & sesi', '/admin/users'],
        'keys' => ['Kunci', '/admin/keys'],
        'audit' => ['Audit log', '/admin/audit'],
        'maintenance' => ['Pemeliharaan', '/admin/maintenance'],
        'settings' => ['Pengaturan', '/admin/settings'],
        'roles' => ['Role admin', '/admin/roles'],
    ];

    /** Tingkat role (makin besar makin berhak). Dipakai untuk mencegah eskalasi hak. */
    private const RANK = ['superadmin' => 3, 'operator' => 2, 'auditor' => 1];

    /**
     * 'audit': read = baca, manage = baca + ekspor CSV. Operator di 'users' tidak boleh mengubah role admin.
     *
     * @return 'none'|'read'|'manage'
     */
    public function level(string $role, string $module): string
    {
        $level = self::MATRIX[$role][$module] ?? 'none';

        return in_array($level, ['read', 'manage'], true) ? $level : 'none';
    }

    /** Apakah $have memenuhi kebutuhan $need. */
    public static function satisfies(string $have, string $need): bool
    {
        $rank = ['none' => 0, 'read' => 1, 'manage' => 2];

        return isset($rank[$have], $rank[$need]) && $rank[$have] >= $rank[$need];
    }

    public static function isRole(string $role): bool
    {
        return isset(self::RANK[$role]);
    }

    /**
     * Admin boleh memberi/mengubah ke $newRole hanya bila punya modul roles:manage dan role-nya tidak lebih
     * rendah dari $newRole; bila target sudah admin ($currentRole), role target pun tidak boleh lebih tinggi.
     */
    public function canAssign(string $actorRole, string $newRole, ?string $currentRole = null): bool
    {
        if (!self::isRole($actorRole) || !self::isRole($newRole) || $this->level($actorRole, 'roles') !== 'manage') {
            return false;
        }
        if ($currentRole !== null && (!self::isRole($currentRole) || self::RANK[$currentRole] > self::RANK[$actorRole])) {
            return false;
        }

        return self::RANK[$newRole] <= self::RANK[$actorRole];
    }

    /** Operator mengelola user, kecuali user yang memegang role admin (kebijakan matriks §8). */
    public function canManageUser(string $actorRole, ?string $targetAdminRole): bool
    {
        if ($this->level($actorRole, 'users') !== 'manage') {
            return false;
        }

        return $targetAdminRole === null || $actorRole === 'superadmin';
    }

    /** Label sel matriks untuk tampilan. */
    public function label(string $role, string $module): string
    {
        $level = $this->level($role, $module);
        if ($level === 'none') {
            return '–';
        }
        if ($level === 'read') {
            return 'Baca';
        }

        return match (true) {
            $module === 'audit' => 'Baca + ekspor',
            $module === 'maintenance' => 'Jalankan',
            $module === 'users' && $role === 'operator' => 'Kelola (kecuali role admin)',
            default => 'Kelola',
        };
    }

    /**
     * Navigasi untuk layout admin.
     *
     * @return array<string, array{label: string, url: string, level: string}>
     */
    public function nav(string $role): array
    {
        $nav = [];
        foreach (self::MODULES as $key => [$label, $url]) {
            $nav[$key] = ['label' => $label, 'url' => $url, 'level' => $this->level($role, $key)];
        }

        return $nav;
    }
}
