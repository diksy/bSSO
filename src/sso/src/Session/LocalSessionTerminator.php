<?php

declare(strict_types=1);

namespace App\Session;

use App\Repository\SsoSessionRepository;
use App\Support\Clock;

/** Modul 2: hanya DB. Digantikan LogoutService (modul 6) di container. */
final class LocalSessionTerminator implements SessionTerminator
{
    public function __construct(private readonly SsoSessionRepository $sessions, private readonly Clock $clock)
    {
    }

    public function endSession(string $sid, string $reason): void
    {
        $this->sessions->revoke($sid, $this->clock->now());
    }

    public function endAllForUser(int $userId, ?string $exceptSid, string $reason): void
    {
        $this->sessions->revokeAllForUser($userId, $exceptSid, $this->clock->now());
    }
}
