<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\SigningKey;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class SigningKeyRepository
{
    private const COLUMNS = 'kid, alg, public_key_pem, private_key_path, status, created_at, activated_at, retiring_at, retired_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function findByKid(string $kid): ?SigningKey
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM signing_keys WHERE kid = ? LIMIT 1');
        $stmt->execute([$kid]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActive(): ?SigningKey
    {
        return $this->listByStatus('active')[0] ?? null;
    }

    /** @return list<SigningKey> */
    public function listByStatus(string ...$statuses): array
    {
        return $this->select($statuses, false);
    }

    /** @return list<SigningKey> selain retired, dikunci (dalam transaction) */
    public function listLiveForUpdate(): array
    {
        return $this->select(['next', 'active', 'retiring'], true);
    }

    public function insert(
        string $kid,
        string $publicKeyPem,
        string $privateKeyPath,
        string $status,
        \DateTimeImmutable $now,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO signing_keys (kid, alg, public_key_pem, private_key_path, status, created_at, activated_at) '
            . "VALUES (?, 'RS256', ?, ?, ?, ?, ?)",
        );
        $stmt->execute([
            $kid, $publicKeyPem, $privateKeyPath, $status, Db::fmt($now), $status === 'active' ? Db::fmt($now) : null,
        ]);
    }

    public function activate(string $kid, \DateTimeImmutable $now): void
    {
        $this->db->pdo()->prepare("UPDATE signing_keys SET status = 'active', activated_at = ? WHERE kid = ?")
            ->execute([Db::fmt($now), $kid]);
    }

    public function markRetiring(string $kid, \DateTimeImmutable $now): void
    {
        $this->db->pdo()->prepare("UPDATE signing_keys SET status = 'retiring', retiring_at = ? WHERE kid = ?")
            ->execute([Db::fmt($now), $kid]);
    }

    public function markRetired(string $kid, \DateTimeImmutable $now): void
    {
        $this->db->pdo()->prepare("UPDATE signing_keys SET status = 'retired', retired_at = ? WHERE kid = ?")
            ->execute([Db::fmt($now), $kid]);
    }

    /**
     * @param list<string> $statuses
     * @return list<SigningKey>
     */
    private function select(array $statuses, bool $forUpdate): array
    {
        if ($statuses === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $sql = 'SELECT ' . self::COLUMNS . ' FROM signing_keys WHERE status IN (' . $in . ') ORDER BY created_at, kid';
        if ($forUpdate && $this->db->isMysql()) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($statuses);

        return array_map(fn (array $r): SigningKey => $this->hydrate($r), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): SigningKey
    {
        return new SigningKey(
            (string) $r['kid'],
            (string) $r['alg'],
            (string) $r['public_key_pem'],
            (string) $r['private_key_path'],
            (string) $r['status'],
            Db::dt($r['created_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            Db::dt($r['activated_at'] ?? null),
            Db::dt($r['retiring_at'] ?? null),
            Db::dt($r['retired_at'] ?? null),
        );
    }
}
