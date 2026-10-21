<?php

declare(strict_types=1);

namespace App\Federation;

final class NormalizedProfile
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly string $providerUserId,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $avatarUrl,
        public readonly array $raw,
    ) {
    }
}
