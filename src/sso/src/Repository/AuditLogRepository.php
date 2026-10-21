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
     * Filter: actor (id atau potongan email/nama), target (idem), event (awalan), from/to (Y-m-d, UTC).
     *
     * @param array<string,mixed> $filter
     * @return list<AuditLog>
     */
    public function listFiltered(array $filter, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filter);
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.user_id, l.target_user_id, l.client_id, l.event, l.ip_address, l.context, l.created_at '
            . 'FROM audit_logs l ' . $where . ' ORDER BY l.id DESC LIMIT ' . max(1, min(1000, $limit)) . ' OFFSET ' . max(0, $offset),
        );
        $stmt->execute($params);

        return array_map(fn (array $r): AuditLog => $this->hydrate($r), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $filter */
    public function countFiltered(array $filter): int
    {
        [$where, $params] = $this->where($filter);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM audit_logs l ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function countEventSince(string $event, \DateTimeImmutable $since): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM audit_logs WHERE event = ? AND created_at >= ?');
        $stmt->execute([$event, Db::fmt($since)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<AuditLog> */
    public function recentByEvent(string $event, int $limit): array
    {
        return $this->listFiltered(['event_exact' => $event], $limit, 0);
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $f): array
    {
        $conds = [];
        $params = [];
        foreach (['actor' => 'user_id', 'target' => 'target_user_id'] as $key => $col) {
            $v = isset($f[$key]) && is_string($f[$key]) ? trim($f[$key]) : '';
            if ($v === '') {
                continue;
            }
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($v, 0, 100)) . '%';
            $conds[] = "(l.$col IN (SELECT u.id FROM users u WHERE u.email LIKE ? ESCAPE '!' OR u.name LIKE ? ESCAPE '!')"
                . (ctype_digit($v) ? " OR l.$col = ?" : '') . ')';
            array_push($params, $like, $like);
            if (ctype_digit($v)) {
                $params[] = (int) $v;
            }
        }
        $event = isset($f['event']) && is_string($f['event']) ? trim($f['event']) : '';
        if ($event !== '') {
            $conds[] = "l.event LIKE ? ESCAPE '!'";
            $params[] = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($event, 0, 50)) . '%';
        }
        if (isset($f['event_exact']) && is_string($f['event_exact'])) {
            $conds[] = 'l.event = ?';
            $params[] = $f['event_exact'];
        }
        if (isset($f['from']) && is_string($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from']) === 1) {
            $conds[] = 'l.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (isset($f['to']) && is_string($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to']) === 1) {
            $conds[] = 'l.created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }

        return [$conds === [] ? '' : 'WHERE ' . implode(' AND ', $conds), $params];
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): AuditLog
    {
        $ctx = isset($r['context']) && is_string($r['context']) ? json_decode($r['context'], true) : null;

        return new AuditLog(
            (int) $r['id'],
            $r['user_id'] === null ? null : (int) $r['user_id'],
            $r['target_user_id'] === null ? null : (int) $r['target_user_id'],
            $r['client_id'] === null ? null : (int) $r['client_id'],
            (string) $r['event'],
            Db::ipFromDb($r['ip_address'] ?? null),
            is_array($ctx) ? $ctx : null,
            Db::dt($r['created_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
