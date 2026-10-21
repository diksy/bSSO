<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\LogoutNotification;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class LogoutNotificationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<LogoutNotification> */
    public function listPending(\DateTimeImmutable $now, int $limit): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
