<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\Client;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class ClientRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByClientId(string $clientId): ?Client
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findById(int $id): ?Client
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<Client> */
    public function listAll(): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
