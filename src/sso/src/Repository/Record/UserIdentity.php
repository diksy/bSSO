<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class UserIdentity
{
    /**
     * @param ?array<string,mixed> $rawProfile
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $providerId,
        public readonly string $providerUserId,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?array $rawProfile,
        public readonly \DateTimeImmutable $linkedAt,
        public readonly ?\DateTimeImmutable $lastLoginAt,
    ) {
    }
}
