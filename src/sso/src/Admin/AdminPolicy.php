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

    /**
     * 'audit': read = baca, manage = baca + ekspor CSV. Operator di 'users' tidak boleh mengubah role admin.
     *
     * @return 'none'|'read'|'manage'
     */
    public function level(string $role, string $module): string
    {
        throw new \LogicException('belum diimplementasi');
    }
}
