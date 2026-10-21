<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class LoginAttempt
{
    public function __construct(
        public readonly int $id,
        public readonly string $identifier,
        public readonly string $ipAddress,
        public readonly bool $success,
        public readonly \DateTimeImmutable $attemptedAt,
    ) {
    }
}
