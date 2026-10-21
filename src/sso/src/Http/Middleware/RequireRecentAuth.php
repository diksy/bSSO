<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Admin\PendingActionStore;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\SsoSession;
use App\Support\Clock;

/**
 * Selalu SETELAH RequireAdmin. mfa_passed_at lebih dari 5 menit (atau kosong) -> simpan aksi tertunda
 * (hanya POST /admin/*) dan arahkan ke /admin/stepup.
 */
final class RequireRecentAuth implements Middleware
{
    public const WINDOW_SECONDS = 300;

    public function __construct(private readonly Clock $clock, private readonly PendingActionStore $pending)
    {
    }

    public function process(Request $r, callable $next): Response
    {
        $session = $r->attribute('sso_session');
        if (!$session instanceof SsoSession || !is_string($r->attribute('admin_role'))) {
            throw new \LogicException('RequireAdmin wajib dijalankan sebelum RequireRecentAuth');
        }
        $at = $session->mfaPassedAt;
        if ($at !== null && $this->clock->now()->getTimestamp() - $at->getTimestamp() <= self::WINDOW_SECONDS) {
            /** @var Response */
            return $next($r);
        }
        $this->pending->save($session, $r->method, $r->path, $r->body);

        return Response::redirect('/admin/stepup', 303);
    }
}
