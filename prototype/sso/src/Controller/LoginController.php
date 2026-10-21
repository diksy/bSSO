<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class LoginController extends BaseController
{
    public const DEMO_PASSWORD = 'demo12345';
    public const DEMO_TOTP = '123456';

    public function form(Request $req, array $p): Response
    {
        return $this->view('auth/login', [
            'title' => 'Masuk',
            'providers' => $this->repo()->activeProviders(),
            'next' => $this->safeNext($req->input('next', '/account')),
            'email' => '',
            'error' => null,
        ]);
    }

    public function submit(Request $req, array $p): Response
    {
        $email = $req->input('email');
        $next = $this->safeNext($req->input('next', '/account'));
        $u = $this->repo()->userByEmail($email);
        $fails = (int) ($_SESSION['fails'] ?? 0);
        $error = null;
        if ($fails >= 5) {
            return $this->redirect('/error/rate');
        }
        if ($u === null || $req->input('password') !== self::DEMO_PASSWORD) {
            $_SESSION['fails'] = $fails + 1;
            $error = 'Email atau kata sandi salah.';
        } elseif ($u['status'] !== 'aktif') {
            $error = 'Akun ini sedang ditangguhkan. Hubungi administrator.';
        }
        if ($error !== null) {
            return $this->view('auth/login', [
                'title' => 'Masuk', 'providers' => $this->repo()->activeProviders(),
                'next' => $next, 'email' => $email, 'error' => $error,
            ], 'auth', 422);
        }
        $_SESSION['fails'] = 0;
        return $this->afterAuth($u, $next);
    }

    public function mfaForm(Request $req, array $p): Response
    {
        if (!isset($_SESSION['pending_uid'])) {
            return $this->redirect('/login');
        }
        return $this->view('auth/mfa', ['title' => 'Verifikasi dua langkah', 'error' => null]);
    }

    public function mfaSubmit(Request $req, array $p): Response
    {
        $uid = $_SESSION['pending_uid'] ?? null;
        $u = is_int($uid) ? ($_SESSION['extra_users'][$uid] ?? $this->repo()->user($uid)) : null;
        if ($u === null) {
            return $this->redirect('/login');
        }
        $code = preg_replace('/\s+/', '', $req->input('code')) ?? '';
        $backup = in_array($code, ['aaaa-1111', 'aaaa1111'], true);
        if ($code !== self::DEMO_TOTP && !$backup) {
            return $this->view('auth/mfa', ['title' => 'Verifikasi dua langkah', 'error' => 'Kode tidak valid. Coba lagi.'], 'auth', 422);
        }
        $next = $this->safeNext((string) ($_SESSION['next'] ?? '/account'));
        $this->login($u);
        return $this->redirect($next);
    }
}
