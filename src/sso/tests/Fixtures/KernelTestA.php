<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Http\Request;
use App\Http\Response;

final class KernelTestA implements \App\Http\Middleware\Middleware
{
    public function process(Request $r, callable $next): Response
    {
        return $next($r->withAttribute('trace', 'A>'));
    }
}
