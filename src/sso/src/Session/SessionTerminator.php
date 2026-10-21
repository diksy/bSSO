<?php

declare(strict_types=1);

namespace App\Session;

interface SessionTerminator
{
    public function endSession(string $sid, string $reason): void;

    public function endAllForUser(int $userId, ?string $exceptSid, string $reason): void;
}
