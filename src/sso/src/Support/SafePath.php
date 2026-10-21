<?php

declare(strict_types=1);

namespace App\Support;

/** Hanya path relatif internal yang boleh jadi tujuan redirect setelah login (anti open redirect). */
final class SafePath
{
    public static function clean(?string $path, string $default = '/account'): string
    {
        if ($path === null || $path === '' || strlen($path) > 2000) {
            return $default;
        }
        if ($path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return $default;
        }

        return $path;
    }
}
