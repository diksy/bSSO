<?php

declare(strict_types=1);

namespace App\Session;

/** Modul 2: hanya DB. Digantikan LogoutService (modul 6) di container. */
final class LocalSessionTerminator implements SessionTerminator
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
