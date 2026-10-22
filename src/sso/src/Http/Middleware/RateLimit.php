<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\OAuth\ClientAuthenticator;
use App\Security\RateLimiter;

/**
 * Batas request per menit untuk endpoint klien (/token 60 per klien, /introspect dan /revoke 120). Kunci klien digabung
 * dengan IP sehingga pihak luar yang mengirim client_id orang lain hanya menghabiskan kuotanya sendiri; ditambah batas per IP.
 * client_id dibaca tanpa autentikasi.
 */
final class RateLimit implements Middleware
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $scope = 'token',
        private readonly int $perClient = 60,
        private readonly int $perIp = 120,
    ) {
    }

    public function process(Request $r, callable $next): Response
    {
        [$clientId] = ClientAuthenticator::credentials($r);
        // Tanpa client_id: batasi per IP agar tidak ada jalur tanpa batas.
        $ip = $r->ip();
        $key = $clientId !== null && $clientId !== '' ? substr($clientId, 0, 80) : 'anon';
        if (
            !$this->limiter->clientEndpointAllowed($this->scope, $key, $ip, $this->perClient, $this->perIp)
        ) {
            return Response::json(['error' => 'temporarily_unavailable'], 429)
                ->withHeader('Retry-After', '60')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Pragma', 'no-cache');
        }

        /** @var Response */
        return $next($r);
    }
}
