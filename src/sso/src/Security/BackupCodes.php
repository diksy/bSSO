<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\Record\User;

final class BackupCodes
{
    /** @return array{plain: list<string>, hashes: list<string>} 10 kode; hash = HMAC */
    public function generate(): array
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** Sekali pakai. */
    public function consume(User $user, string $code): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
