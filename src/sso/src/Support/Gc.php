<?php

declare(strict_types=1);

namespace App\Support;

use App\Repository\Db;

/** Garbage collection (requirements §10): hapus data kedaluwarsa dengan masa tenggang per tabel. */
final class Gc
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /** @return array<string,int> jumlah baris dihapus per tabel */
    public function run(): array
    {
        $now = $this->clock->now();
        $t = static fn (string $mod): string => Db::fmt($now->modify($mod));
        $out = [];
        // Urutan: sesi dihapus terakhir di antara tabel turunannya agar hitungan tiap tabel jelas.
        $out['auth_codes'] = $this->delete('DELETE FROM auth_codes WHERE expires_at < ?', [Db::fmt($now)]);
        $out['federation_states'] = $this->delete('DELETE FROM federation_states WHERE expires_at < ?', [Db::fmt($now)]);
        $out['refresh_tokens'] = $this->delete(
            'DELETE FROM refresh_tokens WHERE expires_at < ? OR revoked_at < ?',
            [$t('-7 days'), $t('-7 days')],
        );
        $out['sso_sessions'] = $this->delete(
            'DELETE FROM sso_sessions WHERE expires_at < ? OR revoked_at < ?',
            [$t('-7 days'), $t('-7 days')],
        );
        $out['login_attempts'] = $this->delete('DELETE FROM login_attempts WHERE attempted_at < ?', [$t('-30 days')]);
        $out['audit_logs'] = $this->delete('DELETE FROM audit_logs WHERE created_at < ?', [$t('-1 year')]);

        return $out;
    }

    /** @param list<string> $params */
    private function delete(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
