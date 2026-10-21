<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Support\UiSession;

/** Mengecualikan POST /token, /introspect, /revoke, /userinfo, /federation/{slug}/callback. */
final class Csrf implements Middleware
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];
    private const EXEMPT_PATHS = ['/token', '/introspect', '/revoke', '/userinfo'];
    private const SESSION_KEY = 'csrf';

    public function process(Request $r, callable $next): Response
    {
        if (in_array($r->method, self::SAFE, true) || self::isExempt($r)) {
            return $next($r);
        }
        $sent = $r->input('_csrf') ?? ($r->headers['x-csrf-token'] ?? '');
        $expected = UiSession::exists() ? UiSession::get(self::SESSION_KEY) : null;
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419);
        }

        return $next($r);
    }

    public static function isExempt(Request $r): bool
    {
        $path = $r->path === '/' ? '/' : rtrim($r->path, '/');
        if (in_array($path, self::EXEMPT_PATHS, true)) {
            return true;
        }

        return $r->method === 'POST' && preg_match('#^/federation/[^/]+/callback$#', $path) === 1;
    }

    /** Token CSRF sesi UI saat ini (dibuat bila belum ada). */
    public static function currentToken(): string
    {
        $token = UiSession::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            UiSession::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** Token baru (dipakai step-up setelah aksi tertunda dijalankan ulang). */
    public static function rotate(): string
    {
        UiSession::forget(self::SESSION_KEY);

        return self::currentToken();
    }
}
