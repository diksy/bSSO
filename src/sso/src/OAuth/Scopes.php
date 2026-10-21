<?php

declare(strict_types=1);

namespace App\OAuth;

final class Scopes
{
    /** Satu token scope: karakter ASCII tercetak tanpa spasi, tanda kutip, dan backslash (RFC 6749 3.3). */
    private const TOKEN = '/^[\x21\x23-\x5B\x5D-\x7E]+$/';

    /** Irisan scope diminta dengan yang diizinkan, dipisah spasi (urutan mengikuti permintaan, tanpa duplikat). */
    public function intersect(string $requested, string $allowed): string
    {
        $allow = self::split($allowed);

        return implode(' ', array_values(array_filter(self::split($requested), static fn (string $s): bool => in_array($s, $allow, true))));
    }

    /** @return list<string> token unik; kosong bila ada token tidak valid */
    public static function split(string $scope): array
    {
        $out = [];
        foreach (preg_split('/ +/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match(self::TOKEN, $token) !== 1) {
                return [];
            }
            $out[$token] = true;
        }

        return array_map('strval', array_keys($out));
    }

    public static function has(string $scope, string $token): bool
    {
        return in_array($token, self::split($scope), true);
    }
}
