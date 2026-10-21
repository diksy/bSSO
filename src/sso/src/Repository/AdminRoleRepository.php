<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\AdminRole;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class AdminRoleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByUserId(int $userId): ?AdminRole
    {
        $stmt = $this->db->pdo()->prepare('SELECT user_id, role, granted_by, granted_at FROM admin_roles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function count(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM admin_roles')->fetchColumn();
    }

    /** @return list<AdminRole> */
    public function listAll(): array
    {
        $rows = $this->db->pdo()->query('SELECT user_id, role, granted_by, granted_at FROM admin_roles ORDER BY user_id')->fetchAll();

        return array_map(fn (array $r): AdminRole => $this->hydrate($r), $rows);
    }

    public function create(int $userId, string $role, ?int $grantedBy, \DateTimeImmutable $at): void
    {
        if (!in_array($role, ['superadmin', 'operator', 'auditor'], true)) {
            throw new \InvalidArgumentException('Role tidak dikenal');
        }
        $this->db->pdo()
            ->prepare('INSERT INTO admin_roles (user_id, role, granted_by, granted_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $role, $grantedBy, Db::fmt($at)]);
    }

    /** Ubah role admin yang sudah ada. */
    public function updateRole(int $userId, string $role, ?int $grantedBy, \DateTimeImmutable $at): bool
    {
        if (!in_array($role, ['superadmin', 'operator', 'auditor'], true)) {
            throw new \InvalidArgumentException('Role tidak dikenal');
        }
        $stmt = $this->db->pdo()->prepare('UPDATE admin_roles SET role = ?, granted_by = ?, granted_at = ? WHERE user_id = ?');
        $stmt->execute([$role, $grantedBy, Db::fmt($at), $userId]);

        return $stmt->rowCount() === 1;
    }

    public function delete(int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM admin_roles WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Kunci semua baris superadmin (hanya di dalam transaction()) agar dua perubahan serentak tidak
     * sama-sama lolos pemeriksaan "superadmin terakhir".
     *
     * @return list<int> user_id superadmin
     */
    public function lockSuperadmins(): array
    {
        $sql = "SELECT user_id FROM admin_roles WHERE role = 'superadmin'" . ($this->db->isMysql() ? ' FOR UPDATE' : '');
        $rows = $this->db->pdo()->query($sql)->fetchAll();

        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): AdminRole
    {
        return new AdminRole(
            (int) $r['user_id'],
            (string) $r['role'],
            $r['granted_by'] === null ? null : (int) $r['granted_by'],
            Db::dt($r['granted_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
