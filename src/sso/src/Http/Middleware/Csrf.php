<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

/** Mengecualikan POST /token, /introspect, /revoke, /userinfo, /federation/{slug}/callback. */
final class Csrf implements Middleware
{
    public function process(Request $r, callable $next): Response
    {
        throw new \LogicException('belum diimplementasi');
    }

    /** Token CSRF sesi UI saat ini (dibuat bila belum ada). */
    public static function currentToken(): string
    {
        throw new \LogicException('belum diimplementasi');
    }
}
