<?php

declare(strict_types=1);

namespace App\Repository;

/** Helper konversi nilai DB (waktu UTC, alamat IP biner) bersama oleh repository. */
final class Db
{
    public static function fmt(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function dt(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public static function ipToDb(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? (string) inet_pton('0.0.0.0') : $packed;
    }

    public static function ipFromDb(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $text = @inet_ntop($value);

        return $text === false ? null : $text;
    }
}
