<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\RefreshToken;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class RefreshTokenRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findByHashForUpdate(string $tokenHash): ?RefreshToken
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<RefreshToken> */
    public function listByFamily(string $familyId): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
