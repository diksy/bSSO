<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\ClientAuthenticator;
use App\Security\RateLimiter;

/** Batas 60 request per klien per menit untuk /token; client_id dibaca tanpa autentikasi. */
final class RateLimit implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function process(Request $r, callable $next): Response
    {
        [$clientId] = ClientAuthenticator::credentials($r);
        // Tanpa client_id: batasi per IP agar tidak ada jalur tanpa batas.
        $key = $clientId !== null && $clientId !== '' ? substr($clientId, 0, 80) : 'ip:' . $r->ip();
        if (!$this->limiter->tokenAllowed($key)) {
            return Response::json(['error' => 'temporarily_unavailable'], 429)
                ->withHeader('Retry-After', '60')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Pragma', 'no-cache');
        }

        /** @var Response */
        return $next($r);
    }
}
