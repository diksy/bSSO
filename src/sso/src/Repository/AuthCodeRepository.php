<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\AuthCode;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class AuthCodeRepository
{
    private const COLUMNS = 'code_hash, client_id, user_id, session_sid, redirect_uri, scope, nonce, code_challenge, '
        . 'code_challenge_method, expires_at, used_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function findByHash(string $codeHash): ?AuthCode
    {
        return $this->one($codeHash, false);
    }

    public function findByHashForUpdate(string $codeHash): ?AuthCode
    {
        return $this->one($codeHash, true);
    }

    public function insert(AuthCode $code): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO auth_codes (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
        )->execute([
            $code->codeHash, $code->clientId, $code->userId, $code->sessionSid, $code->redirectUri, $code->scope,
            $code->nonce, $code->codeChallenge, $code->codeChallengeMethod, Db::fmt($code->expiresAt),
        ]);
    }

    /** Atomik: true hanya bila code belum pernah ditandai terpakai. */
    public function markUsed(string $codeHash, \DateTimeImmutable $at): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE auth_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL');
        $stmt->execute([Db::fmt($at), $codeHash]);

        return $stmt->rowCount() === 1;
    }

    private function one(string $codeHash, bool $forUpdate): ?AuthCode
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM auth_codes WHERE code_hash = ? LIMIT 1';
        if ($forUpdate && $this->db->isMysql()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$codeHash]);
        $r = $stmt->fetch();
        if (!is_array($r)) {
            return null;
        }

        return new AuthCode(
            (string) $r['code_hash'],
            (int) $r['client_id'],
            (int) $r['user_id'],
            (string) $r['session_sid'],
            (string) $r['redirect_uri'],
            (string) $r['scope'],
            $r['nonce'] === null ? null : (string) $r['nonce'],
            (string) $r['code_challenge'],
            (string) $r['code_challenge_method'],
            Db::dt($r['expires_at']) ?? new \DateTimeImmutable('@0'),
            Db::dt($r['used_at'] ?? null),
        );
    }
}
