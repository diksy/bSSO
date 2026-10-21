<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class Client
{
    /**
     * @param list<string> $allowedGrants
     */
    public function __construct(
        public readonly int $id,
        public readonly string $clientId,
        public readonly ?string $clientSecretHash,
        public readonly string $name,
        public readonly string $type,
        public readonly array $allowedGrants,
        public readonly string $allowedScopes,
        public readonly int $accessTokenTtl,
        public readonly int $refreshTokenTtl,
        public readonly ?string $backchannelLogoutUri,
        public readonly bool $isEnabled,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}
