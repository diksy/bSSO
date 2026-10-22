<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Database;

/**
 * Enkripsi ulang kolom terenkripsi ke versi keyring 'current'. Idempoten; satu transaksi per baris dengan
 * UPDATE ... WHERE nilai lama (anti-race). Tidak pernah mengeluarkan plaintext/ciphertext/kunci: laporan hanya
 * berisi nama tabel, kolom, dan id baris. hmac_key tidak dirotasi di sini.
 */
final class Reencryptor
{
    /** @var list<array{table:string,id:string,column:string}> */
    public const TARGETS = [
        ['table' => 'users', 'id' => 'id', 'column' => 'mfa_secret_enc'],
        // Termasuk kunci .p8 Apple (disimpan sebagai client_secret_enc).
        ['table' => 'identity_providers', 'id' => 'id', 'column' => 'client_secret_enc'],
    ];

    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
    }

    /**
     * @return array{updated:int,skipped:int,failed:int,errors:list<string>} updated = jumlah yang akan/telah diubah
     */
    public function run(bool $dryRun): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $pdo = $this->db->pdo();
        $current = $this->crypto->currentVersion();
        foreach (self::TARGETS as $t) {
            $ids = $pdo->query("SELECT {$t['id']} FROM {$t['table']} WHERE {$t['column']} IS NOT NULL")
                ->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $where = $t['table'] . '.' . $t['column'] . ' id=' . (int) $id;
                try {
                    $outcome = $this->processRow($t, (int) $id, $current, $dryRun);
                } catch (\Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $outcome = 'failed';
                }
                if ($outcome === 'failed') {
                    $result['failed']++;
                    $result['errors'][] = 'gagal: ' . $where;
                } elseif ($outcome === 'skipped') {
                    $result['skipped']++;
                } else {
                    $result['updated']++;
                }
            }
        }

        return $result;
    }

    /** @param array{table:string,id:string,column:string} $t */
    private function processRow(array $t, int $id, string $current, bool $dryRun): string
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT {$t['column']} FROM {$t['table']} WHERE {$t['id']} = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetchColumn();
        if (\is_resource($old)) { // @phpstan-ignore if.alwaysFalse
            $old = stream_get_contents($old);
        }
        if (!\is_string($old) || $old === '') {
            $pdo->rollBack();

            return 'skipped';
        }
        if ($this->crypto->versionOf($old) === $current) {
            $pdo->rollBack();

            return 'skipped';
        }
        $new = $this->crypto->reencrypt($old); // melempar bila tidak dapat didekripsi
        if ($dryRun) {
            $pdo->rollBack();

            return 'updated';
        }
        $upd = $pdo->prepare("UPDATE {$t['table']} SET {$t['column']} = ? WHERE {$t['id']} = ? AND {$t['column']} = ?");
        $upd->execute([$new, $id, $old]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();

            return 'failed'; // berubah oleh proses lain
        }
        $pdo->commit();

        return 'updated';
    }
}
