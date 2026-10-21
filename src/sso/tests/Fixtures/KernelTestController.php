<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Http\Request;
use App\Http\Response;

final class KernelTestController
{
    public function hi(Request $r): Response
    {
        return Response::html((string) ($r->attribute('trace') ?? 'ok') . ($r->attribute('name') ?? ''));
    }

    public function boom(Request $r): Response
    {
        throw new \RuntimeException('rahasia-internal');
    }
}
