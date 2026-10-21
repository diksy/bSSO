<?php

declare(strict_types=1);

namespace App\Federation\Drivers;

/** Pembantu kecil untuk driver: normalisasi nilai klaim/field dari provider (tak tepercaya) dengan ketat. */
final class Flag
{
    /** Hanya boolean true atau string "true" (Apple/LinkedIn kadang mengirim string); selain itu false. */
    public static function isTrue(mixed $v): bool
    {
        return $v === true || $v === 'true';
    }

    public static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : (is_int($v) ? (string) $v : null);
    }
}
