<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repository\MockRepository;
use App\Support\View;

abstract class BaseController
{
    protected function repo(): MockRepository
    {
        return new MockRepository();
    }

    protected function view(string $view, array $data = [], string $layout = 'auth', int $status = 200): Response
    {
        $data += ['user' => $this->user(), 'flashes' => pull_flashes()];
        return View::render($view, $data, $layout, $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /** Pengguna login (data dummy + perubahan sesi prototype). */
    protected function user(): ?array
    {
        $id = $_SESSION['uid'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $u = $_SESSION['extra_users'][$id] ?? $this->repo()->user($id);
        if ($u === null) {
            return null;
        }
        $o = $_SESSION['ovr'][$id] ?? [];
        $u = array_replace($u, $o);
        return $u;
    }

    protected function override(array $changes): void
    {
        $id = (int) $_SESSION['uid'];
        $_SESSION['ovr'][$id] = array_replace($_SESSION['ovr'][$id] ?? [], $changes);
    }

    protected function login(array $u): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['mfa_at'] = time();
        unset($_SESSION['pending_uid'], $_SESSION['demo_role'], $_SESSION['pending_action']);
    }

    protected function needLogin(): ?Response
    {
        if ($this->user() === null) {
            return $this->redirect('/login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/account'));
        }
        return null;
    }

    /** Setelah login valid: MFA dulu jika aktif, lalu tujuan akhir. */
    protected function afterAuth(array $u, string $next = '/account'): Response
    {
        $_SESSION['next'] = $next;
        if (!empty($u['mfa'])) {
            $_SESSION['pending_uid'] = (int) $u['id'];
            unset($_SESSION['uid']);
            return $this->redirect('/login/mfa');
        }
        $this->login($u);
        return $this->redirect($next);
    }

    protected function safeNext(string $next): string
    {
        return preg_match('#^/[a-zA-Z0-9/_\-]*$#', $next) === 1 ? $next : '/account';
    }
}
