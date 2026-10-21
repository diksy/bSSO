<?php

declare(strict_types=1);

namespace App\Support;

/** Daftar URL keluar yang diizinkan (konfigurasi provider, backchannel_logout_uri); wajib https. */
final class UrlAllowlist
{
    public function isAllowed(string $url): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
