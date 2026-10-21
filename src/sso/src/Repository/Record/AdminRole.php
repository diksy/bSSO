<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class AdminRole
{
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly ?int $grantedBy,
        public readonly \DateTimeImmutable $grantedAt,
    ) {
    }
}
