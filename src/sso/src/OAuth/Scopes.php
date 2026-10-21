<?php

declare(strict_types=1);

namespace App\OAuth;

final class Scopes
{
    /** Irisan scope diminta dengan yang diizinkan, dipisah spasi. */
    public function intersect(string $requested, string $allowed): string
    {
        throw new \LogicException('belum diimplementasi');
    }
}
