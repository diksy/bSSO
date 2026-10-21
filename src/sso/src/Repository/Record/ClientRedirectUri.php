<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class ClientRedirectUri
{
    public function __construct(
        public readonly int $id,
        public readonly int $clientId,
        public readonly string $uri,
        public readonly string $kind,
    ) {
    }
}
