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

    /** Simpan nilai (JSON). Portabel: cek dulu lalu UPDATE/INSERT; panggil di dalam transaction(). */
    public function set(string $name, mixed $value, ?int $updatedBy, \DateTimeImmutable $at): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if ($this->get($name) === null) {
            $this->db->pdo()
                ->prepare('INSERT INTO settings (name, value, updated_by, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$name, $json, $updatedBy, Db::fmt($at)]);

            return;
        }
        $this->db->pdo()
            ->prepare('UPDATE settings SET value = ?, updated_by = ?, updated_at = ? WHERE name = ?')
            ->execute([$json, $updatedBy, Db::fmt($at), $name]);
    }

    /** Nilai bilangan bulat dengan nilai bawaan. */
    public function int(string $name, int $default): int
    {
        $v = $this->get($name)?->value;

        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : $default;
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
