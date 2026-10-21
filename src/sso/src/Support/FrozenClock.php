<?php

declare(strict_types=1);

namespace App\Support;

/** Clock untuk tes: waktu tetap, bisa dimajukan. */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = '2026-01-01 00:00:00')
    {
        $this->now = $now instanceof \DateTimeImmutable
            ? $now->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
