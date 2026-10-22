<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\Client;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class ClientRepository
{
    private const COLUMNS = 'id, client_id, client_secret_hash, name, type, allowed_grants, allowed_scopes, '
        . 'access_token_ttl, refresh_token_ttl, backchannel_logout_uri, is_enabled, created_at';

    public function __construct(private readonly Database $db)
    {
    }

    public function findByClientId(string $clientId): ?Client
    {
        $row = $this->row('WHERE client_id = ?', [$clientId]);
        // Kolasi MariaDB tidak peka huruf besar/kecil: pastikan kecocokan persis.
        return $row !== null && $row['client_id'] === $clientId ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Client
    {
        $row = $this->row('WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<Client> */
    public function listAll(): array
    {
        $stmt = $this->db->pdo()->query('SELECT ' . self::COLUMNS . ' FROM clients ORDER BY id');
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        return array_map(fn (array $r): Client => $this->hydrate($r), $rows);
    }

    /** @param list<string> $grants */
    public function create(
        string $clientId,
        ?string $secretHash,
        string $name,
        string $type,
        array $grants,
        string $scopes,
        int $accessTtl,
        int $refreshTtl,
        ?string $backchannelUri,
        \DateTimeImmutable $now,
    ): Client {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (client_id, client_secret_hash, name, type, allowed_grants, allowed_scopes, '
            . 'access_token_ttl, refresh_token_ttl, backchannel_logout_uri, is_enabled, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
        );
        $stmt->execute([
            $clientId, $secretHash, $name, $type, implode(',', $grants), $scopes, $accessTtl, $refreshTtl,
            $backchannelUri, Db::fmt($now),
        ]);
        $client = $this->findById((int) $this->db->pdo()->lastInsertId());
        if ($client === null) {
            throw new \RuntimeException('Gagal membuat klien');
        }

        return $client;
    }

    /** @param list<string> $grants */
    public function update(
        int $id,
        string $name,
        array $grants,
        string $scopes,
        int $accessTtl,
        int $refreshTtl,
        ?string $backchannelUri,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE clients SET name = ?, allowed_grants = ?, allowed_scopes = ?, access_token_ttl = ?, '
            . 'refresh_token_ttl = ?, backchannel_logout_uri = ? WHERE id = ?',
        )->execute([$name, implode(',', $grants), $scopes, $accessTtl, $refreshTtl, $backchannelUri, $id]);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET is_enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $id]);
    }

    public function setSecretHash(int $id, string $hash): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET client_secret_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function row(string $where, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM clients ' . $where . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): Client
    {
        $grants = array_values(array_filter(explode(',', (string) $r['allowed_grants']), static fn (string $g): bool => $g !== ''));

        return new Client(
            (int) $r['id'],
            (string) $r['client_id'],
            $r['client_secret_hash'] === null ? null : (string) $r['client_secret_hash'],
            (string) $r['name'],
            (string) $r['type'],
            $grants,
            (string) $r['allowed_scopes'],
            (int) $r['access_token_ttl'],
            (int) $r['refresh_token_ttl'],
            $r['backchannel_logout_uri'] === null ? null : (string) $r['backchannel_logout_uri'],
            (int) $r['is_enabled'] === 1,
            Db::dt($r['created_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
