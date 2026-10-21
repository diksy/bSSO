<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\AdminRole;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class AdminRoleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByUserId(int $userId): ?AdminRole
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function count(): int
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<AdminRole> */
    public function listAll(): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
