<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\LoginAttempt;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class LoginAttemptRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function countFailures(string $identifier, \DateTimeImmutable $since): int
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function countFailuresByIp(string $ip, \DateTimeImmutable $since): int
    {
        throw new \LogicException('belum diimplementasi');
    }
}
