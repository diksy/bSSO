<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\IdentityProvider;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class IdentityProviderRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findBySlug(string $slug): ?IdentityProvider
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function findById(int $id): ?IdentityProvider
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<IdentityProvider> */
    public function listEnabled(): array
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<IdentityProvider> */
    public function listAll(): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
