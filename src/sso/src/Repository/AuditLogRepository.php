<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\AuditLog;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class AuditLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string,mixed> $filter
     * @return list<AuditLog>
     */
    public function listFiltered(array $filter, int $limit, int $offset): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
