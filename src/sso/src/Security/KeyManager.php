<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\Record\SigningKey;

final class KeyManager
{
    public function active(): SigningKey
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** @return array{keys: list<array<string,string>>} kunci next/active/retiring */
    public function jwks(): array
    {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * @param array<string,mixed> $claims
     * @param string $typ header typ: 'JWT' | 'at+jwt' | 'logout+jwt'
     */
    public function sign(array $claims, string $typ): string
    {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * Hanya RS256; kid wajib dan berstatus next/active/retiring.
     *
     * @return array<string,mixed> claims
     */
    public function verify(string $jwt, bool $allowExpired = false): array
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function rotate(bool $emergency = false): RotationResult
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** Rotasi terjadwal (key.rotation_days), idempoten; null jika belum jatuh tempo. */
    public function rotateIfDue(): ?RotationResult
    {
        throw new \LogicException('belum diimplementasi');
    }
}
