<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\SessionClient;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class SessionClientRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<SessionClient> */
    public function listForSession(string $sessionSid): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
