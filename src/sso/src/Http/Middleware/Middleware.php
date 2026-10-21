<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

interface Middleware
{
    /** @param callable(Request): Response $next */
    public function process(Request $r, callable $next): Response;
}
