<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\User;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?User
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findByEmail(string $email): ?User
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findByUuid(string $uuid): ?User
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findByIdForUpdate(int $id): ?User
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function create(string $email, ?string $passwordHash, ?string $name, string $status = 'active'): User
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<User> */
    public function search(string $q, int $limit = 50, int $offset = 0): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
