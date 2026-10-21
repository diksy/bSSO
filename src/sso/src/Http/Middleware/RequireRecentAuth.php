<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class RequireRecentAuth implements Middleware
{
    public function process(Request $r, callable $next): Response
    {
        throw new \LogicException('belum diimplementasi');
    }
}
