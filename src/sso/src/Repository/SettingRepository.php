<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\Setting;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class SettingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $name): ?Setting
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return list<Setting> */
    public function listAll(): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
