<?php

declare(strict_types=1);

namespace App\Account;

use App\Repository\AdminRoleRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\PasswordPolicy;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;

/** Membuat superadmin pertama (dipakai bin/create-admin.php). Menolak bila sudah ada admin. */
final class AdminCreator
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly AdminRoleRepository $roles,
        private readonly PasswordHasher $hasher,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @throws \InvalidArgumentException|\RuntimeException pesan aman ditampilkan di CLI */
    public function create(string $email, string $name, string $password): int
    {
        $email = UserRepository::normalizeEmail($email);
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Alamat email tidak valid.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new \InvalidArgumentException('Nama wajib diisi (maksimal 80 karakter).');
        }
        $policy = PasswordPolicy::check($password);
        if ($policy !== null) {
            throw new \InvalidArgumentException($policy);
        }
        $hash = $this->hasher->hash($password);

        $id = $this->db->transaction(function () use ($email, $name, $hash): int {
            if ($this->roles->count() > 0) {
                throw new \RuntimeException('Admin sudah ada; gunakan halaman admin untuk mengelola role.');
            }
            if ($this->users->findByEmail($email) !== null) {
                throw new \RuntimeException('Email sudah terdaftar.');
            }
            $now = $this->clock->now();
            $user = $this->users->create($email, $hash, $name, 'active');
            $this->users->markEmailVerified($user->id, $now);
            $this->roles->create($user->id, 'superadmin', null, $now);

            return $user->id;
        });
        $this->audit->record('admin.bootstrap', null, $id);

        return $id;
    }
}
