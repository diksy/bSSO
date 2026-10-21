<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Http\Request;
use App\Http\Response;

final class KernelTestB implements \App\Http\Middleware\Middleware
{
    public function __construct(private readonly string $arg)
    {
    }

    public function process(Request $r, callable $next): Response
    {
        return $next($r->withAttribute('trace', $r->attribute('trace') . 'B(' . $this->arg . ')>'));
    }
}
