<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\UserIdentity;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class UserIdentityRepository
{
    private const COLUMNS = 'id, user_id, provider_id, provider_user_id, email, email_verified, raw_profile, linked_at, last_login_at';
    private const MAX_RAW = 16384;

    public function __construct(private readonly Database $db)
    {
    }

    public function findByProviderUser(int $providerId, string $providerUserId): ?UserIdentity
    {
        return $this->one('WHERE provider_id = ? AND provider_user_id = ?', [$providerId, $providerUserId]);
    }

    public function findByUserAndProvider(int $userId, int $providerId): ?UserIdentity
    {
        return $this->one('WHERE user_id = ? AND provider_id = ?', [$userId, $providerId]);
    }

    /** @return list<UserIdentity> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM user_identities WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);

        return array_map(fn (array $r): UserIdentity => $this->hydrate($r), $stmt->fetchAll());
    }

    /**
     * @param ?array<string,mixed> $rawProfile
     * @throws \PDOException bila (provider_id, provider_user_id) sudah ada (UNIQUE)
     */
    public function create(
        int $userId,
        int $providerId,
        string $providerUserId,
        ?string $email,
        bool $emailVerified,
        ?array $rawProfile,
        \DateTimeImmutable $at,
    ): UserIdentity {
        $raw = $rawProfile === null ? null : json_encode($rawProfile);
        if ($raw === false || ($raw !== null && strlen($raw) > self::MAX_RAW)) {
            $raw = null;
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO user_identities (user_id, provider_id, provider_user_id, email, email_verified, raw_profile, linked_at, last_login_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $userId, $providerId, $providerUserId, $email === null ? null : mb_substr($email, 0, 255),
            $emailVerified ? 1 : 0, $raw, Db::fmt($at), Db::fmt($at),
        ]);
        $identity = $this->findByProviderUser($providerId, $providerUserId);
        if ($identity === null) {
            throw new \RuntimeException('Gagal membuat identitas');
        }

        return $identity;
    }

    public function touchLogin(int $id, \DateTimeImmutable $at, ?string $email, bool $emailVerified): void
    {
        $this->db->pdo()->prepare('UPDATE user_identities SET last_login_at = ?, email = COALESCE(?, email), email_verified = ? WHERE id = ?')
            ->execute([Db::fmt($at), $email === null ? null : mb_substr($email, 0, 255), $emailVerified ? 1 : 0, $id]);
    }

    /** Hapus tautan milik user (kondisi user_id di WHERE: tak bisa menghapus milik orang lain). */
    public function deleteForUser(int $userId, int $identityId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM user_identities WHERE id = ? AND user_id = ?');
        $stmt->execute([$identityId, $userId]);

        return $stmt->rowCount() === 1;
    }

    /** @param list<mixed> $params */
    private function one(string $where, array $params): ?UserIdentity
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM user_identities ' . $where . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): UserIdentity
    {
        $raw = is_string($r['raw_profile'] ?? null) ? json_decode($r['raw_profile'], true) : null;

        return new UserIdentity(
            (int) $r['id'],
            (int) $r['user_id'],
            (int) $r['provider_id'],
            (string) $r['provider_user_id'],
            $r['email'] === null ? null : (string) $r['email'],
            (bool) $r['email_verified'],
            is_array($raw) ? $raw : null,
            Db::dt($r['linked_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            Db::dt($r['last_login_at'] ?? null),
        );
    }
}
