<?php

declare(strict_types=1);

namespace App\Support;

final class Audit
{
    /**
     * Menyaring kunci context yang cocok password|secret|token|code sebelum disimpan.
     *
     * @param array<string,mixed> $context
     */
    public function record(
        string $event,
        ?int $actor,
        ?int $target = null,
        ?int $clientId = null,
        array $context = [],
    ): void {
        throw new \LogicException('belum diimplementasi');
    }
}
