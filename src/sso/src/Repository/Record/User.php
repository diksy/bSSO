<?php

declare(strict_types=1);

namespace App\Repository\Record;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly ?string $email,
        public readonly ?\DateTimeImmutable $emailVerifiedAt,
        public readonly ?string $passwordHash,
        public readonly ?string $name,
        public readonly ?string $avatarUrl,
        public readonly ?string $mfaSecretEnc,
        public readonly ?\DateTimeImmutable $mfaEnabledAt,
        public readonly ?string $mfaBackupCodes,
        public readonly ?int $mfaLastStep,
        public readonly string $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
