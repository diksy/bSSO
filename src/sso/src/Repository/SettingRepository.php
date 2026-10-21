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
        $stmt = $this->db->pdo()->prepare('SELECT name, value, updated_by, updated_at FROM settings WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<Setting> */
    public function listAll(): array
    {
        $rows = $this->db->pdo()->query('SELECT name, value, updated_by, updated_at FROM settings ORDER BY name')->fetchAll();

        return array_map(fn (array $r): Setting => $this->hydrate($r), $rows);
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): Setting
    {
        $value = json_decode((string) $r['value'], true);

        return new Setting(
            (string) $r['name'],
            $value,
            isset($r['updated_by']) ? (int) $r['updated_by'] : null,
            Db::dt($r['updated_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
