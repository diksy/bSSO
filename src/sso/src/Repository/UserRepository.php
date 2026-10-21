<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\User;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class UserRepository
{
    private const COLUMNS = 'id, uuid, email, email_verified_at, password_hash, name, avatar_url, mfa_secret_enc, '
        . 'mfa_enabled_at, mfa_backup_codes, mfa_last_step, status, created_at, updated_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?User
    {
        return $this->one('WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->one('WHERE email = ?', [self::normalizeEmail($email)]);
    }

    public function findByUuid(string $uuid): ?User
    {
        return $this->one('WHERE uuid = ?', [$uuid]);
    }

    public function findByIdForUpdate(int $id): ?User
    {
        return $this->one('WHERE id = ?', [$id], true);
    }

    public function create(string $email, ?string $passwordHash, ?string $name, string $status = 'active'): User
    {
        $now = Db::fmt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $uuid = self::uuid4();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (uuid, email, password_hash, name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$uuid, self::normalizeEmail($email), $passwordHash, $name, $status, $now, $now]);
        $user = $this->findById((int) $this->db->pdo()->lastInsertId());
        if ($user === null) {
            throw new \RuntimeException('Gagal membuat user');
        }

        return $user;
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->exec('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [$hash, $this->now(), $id]);
    }

    /**
     * Pendaftaran ulang atas akun yang belum terverifikasi: menimpa password_hash dan nama secara atomik.
     * Akun active/terverifikasi tidak pernah tersentuh (kondisi di WHERE). True bila baris diperbarui.
     */
    public function replacePendingRegistration(int $id, string $hash, string $name): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = ?, name = ?, updated_at = ? '
            . "WHERE id = ? AND status = 'pending' AND email_verified_at IS NULL",
        );
        $stmt->execute([$hash, $name, $this->now(), $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Akun pra-daftar yang belum terverifikasi diambil alih pemilik email sah (terbukti lewat provider tepercaya):
     * password_hash dikosongkan (penyerang pra-pendaftaran kehilangan akses), email ditandai terverifikasi, status active.
     * True bila baris berubah; akun terverifikasi tidak pernah tersentuh.
     */
    public function claimUnverifiedByFederation(int $id, \DateTimeImmutable $at): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = NULL, email_verified_at = ?, status = ?, updated_at = ? '
            . "WHERE id = ? AND email_verified_at IS NULL AND status IN ('pending', 'active')",
        );
        $stmt->execute([Db::fmt($at), 'active', $this->now(), $id]);

        return $stmt->rowCount() === 1;
    }

    public function updateName(int $id, string $name): void
    {
        $this->exec('UPDATE users SET name = ?, updated_at = ? WHERE id = ?', [$name, $this->now(), $id]);
    }

    /**
     * Aktifkan MFA hanya bila belum aktif (atomik). $step = langkah TOTP yang dipakai verifikasi (anti-replay).
     *
     * @param list<string> $codeHashes
     */
    public function enableMfa(int $id, string $secretEnc, array $codeHashes, int $step, \DateTimeImmutable $at): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET mfa_secret_enc = ?, mfa_enabled_at = ?, mfa_backup_codes = ?, mfa_last_step = ?, updated_at = ? '
            . 'WHERE id = ? AND mfa_enabled_at IS NULL',
        );
        $stmt->execute([$secretEnc, Db::fmt($at), json_encode($codeHashes), $step, $this->now(), $id]);

        return $stmt->rowCount() === 1;
    }

    public function disableMfa(int $id): void
    {
        $this->exec(
            'UPDATE users SET mfa_secret_enc = NULL, mfa_enabled_at = NULL, mfa_backup_codes = NULL, mfa_last_step = NULL, '
            . 'updated_at = ? WHERE id = ?',
            [$this->now(), $id],
        );
    }

    /** @param list<string> $codeHashes */
    public function setBackupCodes(int $id, array $codeHashes): void
    {
        $this->exec(
            'UPDATE users SET mfa_backup_codes = ?, updated_at = ? WHERE id = ? AND mfa_enabled_at IS NOT NULL',
            [json_encode($codeHashes), $this->now(), $id],
        );
    }

    /** Anti-replay atomik: true hanya bila $step lebih baru dari mfa_last_step. */
    public function claimMfaStep(int $id, int $step): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET mfa_last_step = ? WHERE id = ? AND mfa_enabled_at IS NOT NULL '
            . 'AND (mfa_last_step IS NULL OR mfa_last_step < ?)',
        );
        $stmt->execute([$step, $id, $step]);

        return $stmt->rowCount() === 1;
    }

    /** Idempoten: hanya mengisi email_verified_at bila kosong; status pending menjadi active. */
    public function markEmailVerified(int $id, \DateTimeImmutable $at): void
    {
        $this->exec(
            'UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?',
            [Db::fmt($at), $this->now(), $id],
        );
        $this->exec("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'", [$id]);
    }

    /** @return list<User> */
    public function search(string $q, int $limit = 50, int $offset = 0): array
    {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM users WHERE email LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!' "
            . 'ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
        );
        $stmt->execute([$like, $like]);

        return array_map(fn (array $r): User => $this->hydrate($r), $stmt->fetchAll());
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @param list<mixed> $params */
    private function one(string $where, array $params, bool $forUpdate = false): ?User
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM users ' . $where . ' LIMIT 1';
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

    private function now(): string
    {
        return Db::fmt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): User
    {
        return new User(
            (int) $r['id'],
            (string) $r['uuid'],
            $r['email'] === null ? null : (string) $r['email'],
            Db::dt($r['email_verified_at'] ?? null),
            $r['password_hash'] === null ? null : (string) $r['password_hash'],
            $r['name'] === null ? null : (string) $r['name'],
            $r['avatar_url'] === null ? null : (string) $r['avatar_url'],
            $r['mfa_secret_enc'] === null ? null : (string) $r['mfa_secret_enc'],
            Db::dt($r['mfa_enabled_at'] ?? null),
            $r['mfa_backup_codes'] === null ? null : (string) $r['mfa_backup_codes'],
            $r['mfa_last_step'] === null ? null : (int) $r['mfa_last_step'],
            (string) $r['status'],
            Db::dt($r['created_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            Db::dt($r['updated_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }
}
