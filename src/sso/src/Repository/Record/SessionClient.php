<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class SessionClient
{
    public function __construct(
        public readonly string $sessionSid,
        public readonly int $clientId,
        public readonly \DateTimeImmutable $firstLoginAt,
    ) {
    }
}
