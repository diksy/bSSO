<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\RefreshToken;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class RefreshTokenRepository
{
    private const COLUMNS = 'token_hash, family_id, auth_code_hash, client_id, user_id, session_sid, scope, issued_at, '
        . 'expires_at, used_at, revoked_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        return $this->one($tokenHash, false);
    }

    public function findByHashForUpdate(string $tokenHash): ?RefreshToken
    {
        return $this->one($tokenHash, true);
    }

    /** @return list<RefreshToken> */
    public function listByFamily(string $familyId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM refresh_tokens WHERE family_id = ? ORDER BY issued_at');
        $stmt->execute([$familyId]);

        return array_map(fn (array $r): RefreshToken => $this->hydrate($r), $stmt->fetchAll());
    }

    public function insert(RefreshToken $t): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO refresh_tokens (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)',
        )->execute([
            $t->tokenHash, $t->familyId, $t->authCodeHash, $t->clientId, $t->userId, $t->sessionSid, $t->scope,
            Db::fmt($t->issuedAt), Db::fmt($t->expiresAt),
        ]);
    }

    /** Atomik: true hanya bila token belum dipakai dan belum dicabut. */
    public function markUsed(string $tokenHash, \DateTimeImmutable $at): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE refresh_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND revoked_at IS NULL',
        );
        $stmt->execute([Db::fmt($at), $tokenHash]);

        return $stmt->rowCount() === 1;
    }

    public function revokeFamily(string $familyId, \DateTimeImmutable $at): int
    {
        $stmt = $this->db->pdo()->prepare('UPDATE refresh_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL');
        $stmt->execute([Db::fmt($at), $familyId]);

        return $stmt->rowCount();
    }

    /** Pencabutan semua token yang terbit dari satu authorization code (reuse code). */
    public function revokeByAuthCodeHash(string $codeHash, \DateTimeImmutable $at): int
    {
        $stmt = $this->db->pdo()->prepare('UPDATE refresh_tokens SET revoked_at = ? WHERE auth_code_hash = ? AND revoked_at IS NULL');
        $stmt->execute([Db::fmt($at), $codeHash]);

        return $stmt->rowCount();
    }

    private function one(string $tokenHash, bool $forUpdate): ?RefreshToken
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM refresh_tokens WHERE token_hash = ? LIMIT 1';
        if ($forUpdate && $this->db->isMysql()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$tokenHash]);
        $r = $stmt->fetch();

        return is_array($r) ? $this->hydrate($r) : null;
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): RefreshToken
    {
        $epoch = new \DateTimeImmutable('@0');

        return new RefreshToken(
            (string) $r['token_hash'],
            (string) $r['family_id'],
            $r['auth_code_hash'] === null ? null : (string) $r['auth_code_hash'],
            (int) $r['client_id'],
            (int) $r['user_id'],
            $r['session_sid'] === null ? null : (string) $r['session_sid'],
            (string) $r['scope'],
            Db::dt($r['issued_at']) ?? $epoch,
            Db::dt($r['expires_at']) ?? $epoch,
            Db::dt($r['used_at'] ?? null),
            Db::dt($r['revoked_at'] ?? null),
        );
    }
}
