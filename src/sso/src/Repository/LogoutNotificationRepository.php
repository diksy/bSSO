<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\LogoutNotification;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class LogoutNotificationRepository
{
    public const MAX_ATTEMPTS = 5;
    private const COLUMNS = 'id, client_id, sid, user_uuid, attempts, next_try_at, delivered_at, last_error';

    public function __construct(private readonly Database $db)
    {
    }

    public function insert(int $clientId, string $sid, string $userUuid, \DateTimeImmutable $nextTryAt): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO logout_notifications (client_id, sid, user_uuid, attempts, next_try_at) VALUES (?, ?, ?, 0, ?)',
        )->execute([$clientId, $sid, $userUuid, Db::fmt($nextTryAt)]);
    }

    /**
     * Belum terkirim, belum melewati batas percobaan, dan sudah waktunya.
     *
     * @return list<LogoutNotification>
     */
    public function listPending(\DateTimeImmutable $now, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM logout_notifications WHERE delivered_at IS NULL AND attempts < ? '
            . 'AND next_try_at <= ? ORDER BY next_try_at, id LIMIT ' . max(1, min($limit, 500)),
        );
        $stmt->execute([self::MAX_ATTEMPTS, Db::fmt($now)]);

        return array_map(fn (array $r): LogoutNotification => $this->hydrate($r), $stmt->fetchAll());
    }

    /** @return list<LogoutNotification> */
    public function listBySid(string $sid): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM logout_notifications WHERE sid = ? ORDER BY id');
        $stmt->execute([$sid]);

        return array_map(fn (array $r): LogoutNotification => $this->hydrate($r), $stmt->fetchAll());
    }

    /** Klaim atomik (lease): hanya satu pemroses yang menggeser next_try_at dari nilai yang dibaca. */
    public function claim(int $id, \DateTimeImmutable $expectedNextTry, \DateTimeImmutable $lease): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE logout_notifications SET next_try_at = ? WHERE id = ? AND next_try_at = ? AND delivered_at IS NULL AND attempts < ?',
        );
        $stmt->execute([Db::fmt($lease), $id, Db::fmt($expectedNextTry), self::MAX_ATTEMPTS]);

        return $stmt->rowCount() === 1;
    }

    public function markDelivered(int $id, \DateTimeImmutable $at): void
    {
        $this->db->pdo()->prepare('UPDATE logout_notifications SET delivered_at = ?, last_error = NULL WHERE id = ?')
            ->execute([Db::fmt($at), $id]);
    }

    /** Tambah attempts, simpan galat singkat, jadwalkan ulang (atau permanen gagal bila attempts mencapai batas). */
    public function markFailed(int $id, string $error, \DateTimeImmutable $nextTryAt): void
    {
        $this->db->pdo()->prepare(
            'UPDATE logout_notifications SET attempts = attempts + 1, last_error = ?, next_try_at = ? WHERE id = ?',
        )->execute([mb_substr($error, 0, 255), Db::fmt($nextTryAt), $id]);
    }

    /** Gagal permanen langsung (mis. klien tanpa endpoint). */
    public function markPermanentFailure(int $id, string $error): void
    {
        $this->db->pdo()->prepare('UPDATE logout_notifications SET attempts = ?, last_error = ? WHERE id = ?')
            ->execute([self::MAX_ATTEMPTS, mb_substr($error, 0, 255), $id]);
    }

    public function findById(int $id): ?LogoutNotification
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM logout_notifications WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();

        return is_array($r) ? $this->hydrate($r) : null;
    }

    public function countPermanentFailures(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM logout_notifications WHERE delivered_at IS NULL AND attempts >= ?');
        $stmt->execute([self::MAX_ATTEMPTS]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<LogoutNotification> gagal permanen, terbaru dulu */
    public function listPermanentFailures(int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM logout_notifications WHERE delivered_at IS NULL AND attempts >= ? '
            . 'ORDER BY next_try_at DESC, id DESC LIMIT ' . max(1, min($limit, 100)),
        );
        $stmt->execute([self::MAX_ATTEMPTS]);

        return array_map(fn (array $r): LogoutNotification => $this->hydrate($r), $stmt->fetchAll());
    }

    /** Admin "retry": hidupkan lagi yang gagal permanen. */
    public function resetFailed(\DateTimeImmutable $now): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE logout_notifications SET attempts = 0, next_try_at = ? WHERE delivered_at IS NULL AND attempts >= ?',
        );
        $stmt->execute([Db::fmt($now), self::MAX_ATTEMPTS]);

        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): LogoutNotification
    {
        $epoch = new \DateTimeImmutable('@0');

        return new LogoutNotification(
            (int) $r['id'],
            (int) $r['client_id'],
            (string) $r['sid'],
            (string) $r['user_uuid'],
            (int) $r['attempts'],
            Db::dt($r['next_try_at']) ?? $epoch,
            Db::dt($r['delivered_at'] ?? null),
            $r['last_error'] === null ? null : (string) $r['last_error'],
        );
    }
}
