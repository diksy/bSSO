<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\AuthCode;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class AuthCodeRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByHash(string $codeHash): ?AuthCode
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findByHashForUpdate(string $codeHash): ?AuthCode
    {
        throw new \LogicException('belum diimplementasi');
    }
}
