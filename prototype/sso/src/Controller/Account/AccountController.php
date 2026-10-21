<?php
declare(strict_types=1);

namespace App\Controller\Account;

use App\Controller\BaseController;
use App\Http\Request;
use App\Http\Response;

final class AccountController extends BaseController
{
    private function page(string $view, string $title, string $tab, array $data = [], int $status = 200): Response
    {
        return $this->view($view, $data + ['title' => $title, 'tab' => $tab], 'account', $status);
    }

    public function profile(Request $req, array $p): Response
    {
        return $this->needLogin() ?? $this->page('account/profile', 'Profil', 'profile');
    }

    public function profileSave(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $name = $req->input('name');
        if ($name === '' || mb_strlen($name) > 80) {
            return $this->page('account/profile', 'Profil', 'profile', ['error' => 'Nama wajib diisi (maks. 80 karakter).'], 422);
        }
        $this->override(['name' => $name]);
        flash('Profil disimpan.');
        return $this->redirect('/account');
    }

    public function password(Request $req, array $p): Response
    {
        return $this->needLogin() ?? $this->page('account/password', 'Ganti kata sandi', 'password', ['error' => null]);
    }

    public function passwordSave(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $err = null;
        if ($req->input('current') !== 'demo12345') {
            $err = 'Kata sandi saat ini salah.';
        } elseif (strlen($req->input('new')) < 12) {
            $err = 'Kata sandi baru minimal 12 karakter.';
        } elseif ($req->input('new') !== $req->input('confirm')) {
            $err = 'Konfirmasi tidak sama dengan kata sandi baru.';
        }
        if ($err !== null) {
            return $this->page('account/password', 'Ganti kata sandi', 'password', ['error' => $err], 422);
        }
        flash('Kata sandi diganti (simulasi; kata sandi demo tetap demo12345). Sesi lain dicabut.');
        return $this->redirect('/account/password');
    }

    public function mfa(Request $req, array $p): Response
    {
        return $this->needLogin() ?? $this->page('account/mfa', 'Verifikasi dua langkah', 'mfa', ['codes' => $_SESSION['new_codes'] ?? null, 'error' => null, 'secret' => 'JBSWY3DPEHPK3PXP-DEMO'] + $this->unsetCodes());
    }

    /** Kode cadangan hanya ditampilkan sekali. */
    private function unsetCodes(): array
    {
        unset($_SESSION['new_codes']);
        return [];
    }

    private function makeCodes(): array
    {
        $codes = [];
        for ($i = 1; $i <= 10; $i++) {
            $codes[] = sprintf('demo-%04d-%04d', $i * 137 % 10000, $i * 911 % 10000);
        }
        return $codes;
    }

    public function mfaAction(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $action = $req->input('action');
        if ($action === 'enable') {
            if ($req->input('code') !== '123456') {
                return $this->page('account/mfa', 'Verifikasi dua langkah', 'mfa', ['codes' => null, 'error' => 'Kode tidak valid.', 'secret' => 'JBSWY3DPEHPK3PXP-DEMO'], 422);
            }
            $this->override(['mfa' => true]);
            $_SESSION['new_codes'] = $this->makeCodes();
            flash('MFA diaktifkan. Simpan kode cadangan Anda sekarang.');
        } elseif ($action === 'regen') {
            $_SESSION['new_codes'] = $this->makeCodes();
            flash('Kode cadangan baru dibuat; kode lama tidak berlaku.');
        } elseif ($action === 'disable') {
            if ($this->user()['admin_role'] !== null) {
                flash('Admin wajib memakai MFA, tidak bisa dinonaktifkan.', 'err');
            } else {
                $this->override(['mfa' => false]);
                flash('MFA dinonaktifkan.');
            }
        }
        return $this->redirect('/account/mfa');
    }

    public function linked(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        return $this->page('account/linked', 'Akun tertaut', 'linked', ['providers' => $this->repo()->providers()]);
    }

    public function unlink(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $u = $this->user();
        $left = array_values(array_diff($u['identities'], [$p['slug']]));
        $this->override(['identities' => $left]);
        flash('Tautan dilepas.');
        return $this->redirect('/account/linked');
    }

    public function sessions(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $gone = $_SESSION['revoked'] ?? [];
        $list = array_values(array_filter($this->repo()->sessions(), static fn (array $s): bool => !in_array($s['id'], $gone, true)));
        return $this->page('account/sessions', 'Sesi aktif', 'sessions', ['sessions' => $list]);
    }

    public function revoke(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $_SESSION['revoked'][] = $p['id'];
        flash('Sesi dicabut. Aplikasi terkait diberi tahu lewat logout back-channel.');
        return $this->redirect('/account/sessions');
    }

    public function revokeAll(Request $req, array $p): Response
    {
        if ($r = $this->needLogin()) {
            return $r;
        }
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Anda keluar dari semua perangkat.');
        return $this->redirect('/logged-out');
    }
}
