<?php

declare(strict_types=1);

namespace App\Federation;

final class FederationState
{
    /** @param array<string,mixed> $pendingRequest parameter /authorize asli */
    public function __construct(
        public readonly int $providerId,
        public readonly ?string $nonce,
        public readonly ?string $codeVerifier,
        public readonly array $pendingRequest,
        public readonly ?int $linkToUserId,
    ) {
    }
}
