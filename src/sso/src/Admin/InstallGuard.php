<?php

declare(strict_types=1);

namespace App\Admin;

final class InstallGuard
{
    /** admin_roles kosong DAN storage/install.lock belum ada. */
    public function open(): bool
    {
        throw new \LogicException('belum diimplementasi');
    }
}
