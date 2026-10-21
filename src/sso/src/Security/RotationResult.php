<?php

declare(strict_types=1);

namespace App\Security;

final class RotationResult
{
    /** @param list<string> $actions mis. 'created_next', 'activated', 'retired', 'emergency' */
    public function __construct(
        public readonly array $actions = [],
        public readonly ?string $activeKid = null,
        public readonly ?string $nextKid = null,
    ) {
    }
}
