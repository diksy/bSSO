<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class SigningKey
{
    public function __construct(
        public readonly string $kid,
        public readonly string $alg,
        public readonly string $publicKeyPem,
        public readonly string $privateKeyPath,
        public readonly string $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $activatedAt,
        public readonly ?\DateTimeImmutable $retiringAt,
        public readonly ?\DateTimeImmutable $retiredAt,
    ) {
    }
}
