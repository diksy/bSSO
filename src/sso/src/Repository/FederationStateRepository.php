<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class FederationStateRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(string $stateHash): ?\App\Federation\FederationState
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function delete(string $stateHash): void
    {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * Gabungkan data tambahan ke pending_request tanpa mengonsumsi state.
     *
     * @param array<string,mixed> $extra
     * @return bool false jika state tidak ada/kedaluwarsa
     */
    public function mergePending(string $stateHash, array $extra): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
