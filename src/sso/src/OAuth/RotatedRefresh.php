<?php

declare(strict_types=1);

namespace App\OAuth;

final class RotatedRefresh
{
    public function __construct(
        public readonly string $newToken,
        public readonly int $userId,
        public readonly ?string $sessionSid,
        public readonly string $scope,
    ) {
    }
}
