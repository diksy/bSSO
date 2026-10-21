<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\SessionClient;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class SessionClientRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<SessionClient> */
    public function listForSession(string $sessionSid): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT session_sid, client_id, first_login_at FROM session_clients WHERE session_sid = ?');
        $stmt->execute([$sessionSid]);

        return array_map(
            static fn (array $r): SessionClient => new SessionClient(
                (string) $r['session_sid'],
                (int) $r['client_id'],
                Db::dt($r['first_login_at']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ),
            $stmt->fetchAll(),
        );
    }

    /** Idempoten: baris yang sudah ada dibiarkan (first_login_at pertama dipertahankan). */
    public function add(string $sessionSid, int $clientId, \DateTimeImmutable $now): void
    {
        $verb = $this->db->isMysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $this->db->pdo()->prepare($verb . ' INTO session_clients (session_sid, client_id, first_login_at) VALUES (?, ?, ?)')
            ->execute([$sessionSid, $clientId, Db::fmt($now)]);
    }
}
