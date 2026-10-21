<?php

declare(strict_types=1);

namespace App\Session;

/** Modul 6: transaksi cabut sesi + refresh token + antrean notifikasi, lalu kirim. */
final class LogoutService implements SessionTerminator
{
    public function endSession(string $sid, string $reason): void
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function endAllForUser(int $userId, ?string $exceptSid, string $reason): void
    {
        throw new \LogicException('belum diimplementasi');
    }
}
