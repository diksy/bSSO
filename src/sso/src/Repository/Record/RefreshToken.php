<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class RefreshToken
{
    public function __construct(
        public readonly string $tokenHash,
        public readonly string $familyId,
        public readonly ?string $authCodeHash,
        public readonly int $clientId,
        public readonly int $userId,
        public readonly ?string $sessionIdHash,
        public readonly string $scope,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $usedAt,
        public readonly ?\DateTimeImmutable $revokedAt,
    ) {
    }
}
