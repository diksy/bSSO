<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class RequireAdmin implements Middleware
{
    public function __construct(
        private readonly \App\Admin\AdminPolicy $policy,
        private readonly \App\Repository\AdminRoleRepository $roles,
        public readonly string $module,
        public readonly string $need,
    ) {
    }

    public function process(Request $r, callable $next): Response
    {
        throw new \LogicException('belum diimplementasi');
    }
}
