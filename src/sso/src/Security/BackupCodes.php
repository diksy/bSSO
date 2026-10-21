<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\Record\User;
use App\Repository\UserRepository;
use App\Support\Database;

/** 10 kode cadangan sekali pakai; disimpan hanya sebagai HMAC (JSON list di users.mfa_backup_codes). */
final class BackupCodes
{
    public const COUNT = 10;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly Crypto $crypto,
        private readonly UserRepository $users,
        private readonly Database $db,
    ) {
    }

    /** @return array{plain: list<string>, hashes: list<string>} 10 kode; hash = HMAC */
    public function generate(): array
    {
        $plain = [];
        $hashes = [];
        while (count($plain) < self::COUNT) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $hash = $this->crypto->hmac(self::normalize($code));
            if (!in_array($hash, $hashes, true)) {
                $plain[] = $code;
                $hashes[] = $hash;
            }
        }

        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /** Sekali pakai; atomik (baris user dikunci, kode dihapus dari daftar dalam transaksi yang sama). */
    public function consume(User $user, string $code): bool
    {
        $norm = self::normalize($code);
        if (strlen($norm) !== 10) {
            return false;
        }
        $hash = $this->crypto->hmac($norm);

        return (bool) $this->db->transaction(function () use ($user, $hash): bool {
            $locked = $this->users->findByIdForUpdate($user->id);
            $list = $locked === null || $locked->mfaBackupCodes === null ? [] : json_decode($locked->mfaBackupCodes, true);
            if (!is_array($list)) {
                return false;
            }
            $found = null;
            foreach ($list as $i => $stored) {
                if (is_string($stored) && hash_equals($stored, $hash)) {
                    $found = $i;
                }
            }
            if ($found === null) {
                return false;
            }
            unset($list[$found]);
            $this->users->setBackupCodes($user->id, array_values($list));

            return true;
        });
    }

    public static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/[\s-]+/', '', $code));
    }
}
