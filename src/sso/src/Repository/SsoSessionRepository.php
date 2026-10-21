<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\SsoSession;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class SsoSessionRepository
{
    private const COLUMNS = 'id_hash, sid, user_id, auth_method, mfa_passed_at, ip_address, user_agent, '
        . 'created_at, last_seen_at, expires_at, revoked_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function findByIdHash(string $idHash): ?SsoSession
    {
        return $this->one('WHERE id_hash = ?', [$idHash]);
    }

    public function findBySid(string $sid): ?SsoSession
    {
        return $this->one('WHERE sid = ?', [$sid]);
    }

    /** @return list<SsoSession> */
    public function listActiveForUser(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM sso_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? '
            . 'ORDER BY last_seen_at DESC',
        );
        $stmt->execute([$userId, Db::fmt($now)]);

        return array_map(fn (array $r): SsoSession => $this->hydrate($r), $stmt->fetchAll());
    }

    public function countActive(\DateTimeImmutable $now): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM sso_sessions WHERE revoked_at IS NULL AND expires_at > ?');
        $stmt->execute([Db::fmt($now)]);

        return (int) $stmt->fetchColumn();
    }

    public function findByIdHashForUpdate(string $idHash): ?SsoSession
    {
        return $this->one('WHERE id_hash = ?', [$idHash], true);
    }

    public function create(
        string $idHash,
        string $sid,
        int $userId,
        string $authMethod,
        ?\DateTimeImmutable $mfaPassedAt,
        string $ip,
        ?string $userAgent,
        \DateTimeImmutable $now,
        \DateTimeImmutable $expiresAt,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO sso_sessions (id_hash, sid, user_id, auth_method, mfa_passed_at, ip_address, user_agent, '
            . 'created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->bindValue(1, $idHash);
        $stmt->bindValue(2, $sid);
        $stmt->bindValue(3, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(4, $authMethod);
        $stmt->bindValue(5, $mfaPassedAt === null ? null : Db::fmt($mfaPassedAt));
        $stmt->bindValue(6, Db::ipToDb($ip), \PDO::PARAM_LOB);
        $stmt->bindValue(7, $userAgent === null ? null : mb_substr($userAgent, 0, 255));
        $stmt->bindValue(8, Db::fmt($now));
        $stmt->bindValue(9, Db::fmt($now));
        $stmt->bindValue(10, Db::fmt($expiresAt));
        $stmt->execute();
    }

    public function touch(string $idHash, \DateTimeImmutable $now): void
    {
        $this->exec('UPDATE sso_sessions SET last_seen_at = ? WHERE id_hash = ?', [Db::fmt($now), $idHash]);
    }

    /** Ganti id (cookie baru) dan opsional isi mfa_passed_at; hanya sesi yang belum dicabut. */
    public function replaceIdHash(string $oldHash, string $newHash, ?\DateTimeImmutable $mfaPassedAt): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE sso_sessions SET id_hash = ?, mfa_passed_at = COALESCE(?, mfa_passed_at) '
            . 'WHERE id_hash = ? AND revoked_at IS NULL',
        );
        $stmt->execute([$newHash, $mfaPassedAt === null ? null : Db::fmt($mfaPassedAt), $oldHash]);

        return $stmt->rowCount() === 1;
    }

    public function revoke(string $sid, \DateTimeImmutable $now): void
    {
        $this->exec('UPDATE sso_sessions SET revoked_at = ? WHERE sid = ? AND revoked_at IS NULL', [Db::fmt($now), $sid]);
    }

    /** Atomik: true hanya bila sesi ini yang baru saja berpindah dari aktif ke dicabut (mencegah notifikasi ganda). */
    public function revokeIfActive(string $sid, \DateTimeImmutable $now): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE sso_sessions SET revoked_at = ? WHERE sid = ? AND revoked_at IS NULL');
        $stmt->execute([Db::fmt($now), $sid]);

        return $stmt->rowCount() === 1;
    }

    /**
     * sid semua sesi user yang belum dicabut (termasuk yang sudah kedaluwarsa tapi belum ditandai).
     *
     * @return list<string>
     */
    public function listUnrevokedSidsForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT sid FROM sso_sessions WHERE user_id = ? AND revoked_at IS NULL');
        $stmt->execute([$userId]);

        return array_map(static fn (array $r): string => (string) $r['sid'], $stmt->fetchAll());
    }

    public function revokeAllForUser(int $userId, ?string $exceptSid, \DateTimeImmutable $now): int
    {
        $sql = 'UPDATE sso_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL';
        $params = [Db::fmt($now), $userId];
        if ($exceptSid !== null) {
            $sql .= ' AND sid <> ?';
            $params[] = $exceptSid;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /** @param list<mixed> $params */
    private function one(string $where, array $params, bool $forUpdate = false): ?SsoSession
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM sso_sessions ' . $where . ' LIMIT 1';
        if ($forUpdate && $this->db->isMysql()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param list<mixed> $params */
    private function exec(string $sql, array $params): void
    {
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): SsoSession
    {
        $utc = new \DateTimeZone('UTC');

        return new SsoSession(
            (string) $r['id_hash'],
            (string) $r['sid'],
            (int) $r['user_id'],
            (string) $r['auth_method'],
            Db::dt($r['mfa_passed_at'] ?? null),
            Db::ipFromDb($r['ip_address'] ?? null),
            $r['user_agent'] === null ? null : (string) $r['user_agent'],
            Db::dt($r['created_at']) ?? new \DateTimeImmutable('now', $utc),
            Db::dt($r['last_seen_at']) ?? new \DateTimeImmutable('now', $utc),
            Db::dt($r['expires_at']) ?? new \DateTimeImmutable('now', $utc),
            Db::dt($r['revoked_at'] ?? null),
        );
    }
}
