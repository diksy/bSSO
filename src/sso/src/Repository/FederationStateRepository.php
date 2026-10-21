<?php

declare(strict_types=1);

namespace App\Repository;

use App\Federation\FederationState;
use App\Support\Clock;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class FederationStateRepository
{
    public function __construct(private readonly Database $db, private readonly ?Clock $clock = null)
    {
    }

    /** @param array<string,mixed> $pendingRequest */
    public function create(
        string $stateHash,
        int $providerId,
        ?string $nonce,
        ?string $codeVerifier,
        array $pendingRequest,
        ?int $linkToUserId,
        \DateTimeImmutable $expiresAt,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO federation_states (state_hash, provider_id, nonce, code_verifier, pending_request, link_to_user_id, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $stateHash, $providerId, $nonce, $codeVerifier, json_encode($pendingRequest, JSON_THROW_ON_ERROR),
            $linkToUserId, Db::fmt($expiresAt),
        ]);
    }

    /** Hanya state yang belum kedaluwarsa; TIDAK mengonsumsi. */
    public function find(string $stateHash): ?FederationState
    {
        $row = $this->row($stateHash);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Ambil lalu hapus secara atomik (sekali pakai): hanya satu pemanggil serentak yang berhasil.
     * State kedaluwarsa dihapus dan dikembalikan null.
     */
    public function consume(string $stateHash): ?FederationState
    {
        return $this->db->transaction(function () use ($stateHash): ?FederationState {
            $stmt = $this->db->pdo()->prepare(
                'SELECT state_hash, provider_id, nonce, code_verifier, pending_request, link_to_user_id, expires_at '
                . 'FROM federation_states WHERE state_hash = ?' . ($this->db->isMysql() ? ' FOR UPDATE' : ''),
            );
            $stmt->execute([$stateHash]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return null;
            }
            $del = $this->db->pdo()->prepare('DELETE FROM federation_states WHERE state_hash = ?');
            $del->execute([$stateHash]);
            if ($del->rowCount() !== 1 || $this->expired($row)) {
                return null;
            }

            return $this->hydrate($row);
        });
    }

    public function delete(string $stateHash): void
    {
        $this->db->pdo()->prepare('DELETE FROM federation_states WHERE state_hash = ?')->execute([$stateHash]);
    }

    /**
     * Gabungkan data tambahan ke pending_request tanpa mengonsumsi state. Kunci berawalan "_" (penanda internal)
     * tidak dapat ditimpa.
     *
     * @param array<string,mixed> $extra
     * @return bool false jika state tidak ada/kedaluwarsa
     */
    public function mergePending(string $stateHash, array $extra): bool
    {
        return $this->db->transaction(function () use ($stateHash, $extra): bool {
            $row = $this->row($stateHash, true);
            if ($row === null) {
                return false;
            }
            $current = json_decode((string) $row['pending_request'], true);
            $current = is_array($current) ? $current : [];
            foreach ($extra as $key => $value) {
                if (!str_starts_with((string) $key, '_')) {
                    $current[$key] = $value;
                }
            }
            $this->db->pdo()->prepare('UPDATE federation_states SET pending_request = ? WHERE state_hash = ?')
                ->execute([json_encode($current, JSON_THROW_ON_ERROR), $stateHash]);

            return true;
        });
    }

    /** @return ?array<string,mixed> */
    private function row(string $stateHash, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT state_hash, provider_id, nonce, code_verifier, pending_request, link_to_user_id, expires_at '
            . 'FROM federation_states WHERE state_hash = ?' . ($forUpdate && $this->db->isMysql() ? ' FOR UPDATE' : '');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$stateHash]);
        $row = $stmt->fetch();

        return is_array($row) && !$this->expired($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function expired(array $row): bool
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $exp = Db::dt($row['expires_at']);

        return $exp === null || $exp <= $now;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): FederationState
    {
        $pending = json_decode((string) $row['pending_request'], true);

        return new FederationState(
            (int) $row['provider_id'],
            $row['nonce'] === null ? null : (string) $row['nonce'],
            $row['code_verifier'] === null ? null : (string) $row['code_verifier'],
            is_array($pending) ? $pending : [],
            $row['link_to_user_id'] === null ? null : (int) $row['link_to_user_id'],
        );
    }
}
