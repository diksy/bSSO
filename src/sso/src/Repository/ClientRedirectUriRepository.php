<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\ClientRedirectUri;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class ClientRedirectUriRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<ClientRedirectUri> */
    public function listForClient(int $clientDbId, string $kind): array
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function exists(int $clientDbId, string $uri, string $kind): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
