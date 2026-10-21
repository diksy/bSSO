<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\SigningKey;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class SigningKeyRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByKid(string $kid): ?SigningKey
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findActive(): ?SigningKey
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<SigningKey> */
    public function listByStatus(string ...$statuses): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
