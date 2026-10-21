<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\SsoSession;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class SsoSessionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByIdHash(string $idHash): ?SsoSession
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findBySid(string $sid): ?SsoSession
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<SsoSession> */
    public function listActiveForUser(int $userId): array
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findByIdHashForUpdate(string $idHash): ?SsoSession
    {
        throw new \LogicException('belum diimplementasi');
    }
}
