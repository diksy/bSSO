<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class LoginAttemptRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function add(string $identifier, string $ip, bool $success, \DateTimeImmutable $at): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, success, attempted_at) VALUES (?, ?, ?, ?)',
        );
        $stmt->bindValue(1, mb_substr($identifier, 0, 255));
        $stmt->bindValue(2, Db::ipToDb($ip), \PDO::PARAM_LOB);
        $stmt->bindValue(3, $success ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(4, Db::fmt($at));
        $stmt->execute();
    }

    public function countFailures(string $identifier, \DateTimeImmutable $since): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0 AND attempted_at >= ?',
        );
        $stmt->execute([mb_substr($identifier, 0, 255), Db::fmt($since)]);

        return (int) $stmt->fetchColumn();
    }

    public function countFailuresByIp(string $ip, \DateTimeImmutable $since): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at >= ?',
        );
        $stmt->bindValue(1, Db::ipToDb($ip), \PDO::PARAM_LOB);
        $stmt->bindValue(2, Db::fmt($since));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Semua percobaan (sukses atau gagal) untuk identifier sejak waktu tertentu. */
    public function countAll(string $identifier, \DateTimeImmutable $since): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at >= ?',
        );
        $stmt->execute([mb_substr($identifier, 0, 255), Db::fmt($since)]);

        return (int) $stmt->fetchColumn();
    }
}
