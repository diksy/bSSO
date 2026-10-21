<?php

declare(strict_types=1);

namespace App\Http;

final class Kernel
{
    public function handle(Request $request): Response
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function emit(Response $response): void
    {
        throw new \LogicException('belum diimplementasi');
    }
}
