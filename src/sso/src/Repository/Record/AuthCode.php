<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class AuthCode
{
    public function __construct(
        public readonly string $codeHash,
        public readonly int $clientId,
        public readonly int $userId,
        public readonly string $sessionSid,
        public readonly string $redirectUri,
        public readonly string $scope,
        public readonly ?string $nonce,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $usedAt,
    ) {
    }
}
