<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class SsoSession
{
    public function __construct(
        public readonly string $idHash,
        public readonly string $sid,
        public readonly int $userId,
        public readonly string $authMethod,
        public readonly ?\DateTimeImmutable $mfaPassedAt,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $lastSeenAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $revokedAt,
    ) {
    }
}
