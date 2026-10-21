<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class AuditLog
{
    /**
     * @param ?array<string,mixed> $context
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly ?int $targetUserId,
        public readonly ?int $clientId,
        public readonly string $event,
        public readonly ?string $ipAddress,
        public readonly ?array $context,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
