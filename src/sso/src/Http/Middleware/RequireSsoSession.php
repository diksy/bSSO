<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Repository\UserRepository;
use App\Session\SsoSessionManager;

final class RequireSsoSession implements Middleware
{
    public function __construct(
        private readonly SsoSessionManager $sessions,
        private readonly UserRepository $users,
    ) {
    }

    public function process(Request $r, callable $next): Response
    {
        $session = $this->sessions->current($r);
        $user = $session === null ? null : $this->users->findById($session->userId);
        if ($session === null || $user === null || $user->status !== 'active') {
            $deny = $r->method === 'GET'
                ? Response::redirect('/login?return=' . rawurlencode($this->returnPath($r)))
                : Response::redirect('/login', 303);

            return $session === null ? $deny : $deny->withCookie($this->sessions->clearCookie());
        }
        $this->sessions->touch($session);

        /** @var Response */
        return $next($r->withAttribute('sso_session', $session)->withAttribute('user', $user));
    }

    private function returnPath(Request $r): string
    {
        $query = http_build_query($r->query);

        return $r->path . ($query === '' ? '' : '?' . $query);
    }
}
