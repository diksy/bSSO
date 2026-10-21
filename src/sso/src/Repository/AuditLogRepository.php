<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\AuditLog;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class AuditLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $context sudah disaring Audit */
    public function insert(
        string $event,
        ?int $actor,
        ?int $target,
        ?int $clientId,
        ?string $ip,
        array $context,
        \DateTimeImmutable $at,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_logs (user_id, target_user_id, client_id, event, ip_address, context, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $actor, $actor === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(2, $target, $target === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(3, $clientId, $clientId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(4, mb_substr($event, 0, 50));
        $stmt->bindValue(5, $ip === null ? null : Db::ipToDb($ip), $ip === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(6, $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $stmt->bindValue(7, Db::fmt($at));
        $stmt->execute();
    }

    /**
     * @param array<string,mixed> $filter
     * @return list<AuditLog>
     */
    public function listFiltered(array $filter, int $limit, int $offset): array
    {
        throw new \LogicException('belum diimplementasi');
    }
}
