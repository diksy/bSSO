<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class SecurityHeaders implements Middleware
{
    /** Tanpa form-action: browser memblokir redirect lintas-origin (login lalu kembali ke klien) bila ada. */
    public const CSP = "default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "base-uri 'none'; frame-ancestors 'none'";

    public function process(Request $r, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($r);
        $defaults = [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
        foreach ($defaults as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }
}
