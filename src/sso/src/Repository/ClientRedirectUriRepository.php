<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\ClientRedirectUri;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class ClientRedirectUriRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<ClientRedirectUri> */
    public function listForClient(int $clientDbId, string $kind): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, client_id, uri, kind FROM client_redirect_uris WHERE client_id = ? AND kind = ? ORDER BY id');
        $stmt->execute([$clientDbId, $kind]);

        return array_map(
            static fn (array $r): ClientRedirectUri => new ClientRedirectUri((int) $r['id'], (int) $r['client_id'], (string) $r['uri'], (string) $r['kind']),
            $stmt->fetchAll(),
        );
    }

    /** Exact match byte per byte (dibandingkan di PHP; kolasi DB tidak peka huruf besar/kecil). */
    public function exists(int $clientDbId, string $uri, string $kind): bool
    {
        foreach ($this->listForClient($clientDbId, $kind) as $registered) {
            if ($registered->uri === $uri) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ganti seluruh daftar URI satu jenis (panggil di dalam transaction()).
     *
     * @param list<string> $uris
     */
    public function replace(int $clientDbId, string $kind, array $uris): void
    {
        $this->db->pdo()->prepare('DELETE FROM client_redirect_uris WHERE client_id = ? AND kind = ?')->execute([$clientDbId, $kind]);
        foreach (array_values(array_unique($uris)) as $uri) {
            $this->add($clientDbId, $uri, $kind);
        }
    }

    public function add(int $clientDbId, string $uri, string $kind): void
    {
        $this->db->pdo()->prepare('INSERT INTO client_redirect_uris (client_id, uri, kind) VALUES (?, ?, ?)')
            ->execute([$clientDbId, $uri, $kind]);
    }
}
