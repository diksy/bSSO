<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class IdentityProvider
{
    /**
     * @param array<string,mixed> $extraConfig
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $displayName,
        public readonly string $protocol,
        public readonly string $driver,
        public readonly string $clientId,
        public readonly ?string $clientSecretEnc,
        public readonly ?string $discoveryUrl,
        public readonly ?string $authorizeUrl,
        public readonly ?string $tokenUrl,
        public readonly ?string $userinfoUrl,
        public readonly string $scopes,
        public readonly bool $trustEmail,
        public readonly array $extraConfig,
        public readonly bool $isEnabled,
        public readonly int $sortOrder,
    ) {
    }
}
