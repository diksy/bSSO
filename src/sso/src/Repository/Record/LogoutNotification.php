<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class LogoutNotification
{
    public function __construct(
        public readonly int $id,
        public readonly int $clientId,
        public readonly string $sid,
        public readonly string $userUuid,
        public readonly int $attempts,
        public readonly \DateTimeImmutable $nextTryAt,
        public readonly ?\DateTimeImmutable $deliveredAt,
        public readonly ?string $lastError,
    ) {
    }
}
