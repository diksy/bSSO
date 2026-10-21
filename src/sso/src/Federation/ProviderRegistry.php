<?php

declare(strict_types=1);

namespace App\Federation;

final class ProviderRegistry
{
    /** Daftar putih driver; JANGAN instansiasi class langsung dari string DB. */
    public function get(string $slug): ProviderInterface
    {
        throw new \LogicException('belum diimplementasi');
    }
}
