<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class Setting
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly ?int $updatedBy,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
