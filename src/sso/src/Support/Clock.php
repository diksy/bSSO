<?php

declare(strict_types=1);

namespace App\Support;

interface Clock
{
    /** Waktu sekarang dalam UTC. */
    public function now(): \DateTimeImmutable;
}
