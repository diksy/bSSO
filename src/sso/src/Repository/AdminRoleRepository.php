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
