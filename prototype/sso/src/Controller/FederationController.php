<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

/** Simulasi federasi: pilih skenario cabang IdentityLinker (requirements §5). */
final class FederationController extends BaseController
{
    private function providerOr404(string $slug): ?array
    {
        $pr = $this->repo()->provider($slug);
        return ($pr !== null && $pr['active']) ? $pr : null;
    }

    public function start(Request $req, array $p): Response
    {
        $pr = $this->providerOr404($p['slug']);
        if ($pr === null) {
            return $this->redirect('/error/provider');
        }
        $link = $req->input('link') === '1' && $this->user() !== null;
        return $this->view('auth/federation_start', ['title' => 'Simulasi ' . $pr['name'], 'provider' => $pr, 'link' => $link]);
    }

    public function callback(Request $req, array $p): Response
    {
        $pr = $this->providerOr404($p['slug']);
        if ($pr === null) {
            return $this->redirect('/error/provider');
        }
        $slug = $pr['slug'];
        $scenario = $req->input('scenario');
        $repo = $this->repo();

        if ($req->input('link') === '1' && $this->user() !== null) {
            $ids = array_values(array_unique(array_merge($this->user()['identities'], [$slug])));
            $this->override(['identities' => $ids]);
            flash('Akun ' . $pr['name'] . ' berhasil ditautkan.');
            return $this->redirect('/account/linked');
        }
        switch ($scenario) {
            case 'existing':
                $u = $repo->user(4);
                flash('Anda masuk lewat ' . $pr['name'] . ' (identitas sudah tertaut).');
                return $this->afterAuth($u, '/account');
            case 'auto':
                $u = $repo->user(5);
                $_SESSION['ovr'][5]['identities'] = array_values(array_unique(array_merge($u['identities'], [$slug])));
                flash('Email terverifikasi dan cocok dengan akun Anda; ' . $pr['name'] . ' ditautkan otomatis.');
                return $this->afterAuth($_SESSION['ovr'][5] + $u, '/account');
            case 'confirm':
                $_SESSION['fed'] = ['slug' => $slug, 'uid' => 4];
                return $this->redirect('/federation/' . rawurlencode($slug) . '/confirm-link');
            case 'new':
                return $this->createNew($pr, 'Rini Kusuma', 'rini.baru@contoh.test');
            default:
                $_SESSION['fed'] = ['slug' => $slug];
                return $this->redirect('/federation/' . rawurlencode($slug) . '/complete-email');
        }
    }

    private function createNew(array $pr, string $name, string $email): Response
    {
        $id = 101;
        $_SESSION['extra_users'][$id] = ['id' => $id, 'name' => $name, 'email' => $email, 'status' => 'aktif', 'mfa' => false, 'admin_role' => null, 'identities' => [$pr['slug']], 'last_login' => date('Y-m-d H:i'), 'created' => date('Y-m-d')];
        $this->login($_SESSION['extra_users'][$id]);
        flash('Akun baru dibuat lewat ' . $pr['name'] . '. Selamat datang di BikininSSO!');
        return $this->redirect('/account');
    }

    public function confirmForm(Request $req, array $p): Response
    {
        $fed = $_SESSION['fed'] ?? null;
        $pr = $this->repo()->provider($p['slug']);
        if (!is_array($fed) || !isset($fed['uid']) || $pr === null) {
            return $this->redirect('/error/state');
        }
        return $this->view('auth/confirm_link', ['title' => 'Konfirmasi tautan akun', 'provider' => $pr, 'target' => $this->repo()->user((int) $fed['uid']), 'error' => null]);
    }

    public function confirmSubmit(Request $req, array $p): Response
    {
        $fed = $_SESSION['fed'] ?? null;
        $pr = $this->repo()->provider($p['slug']);
        if (!is_array($fed) || !isset($fed['uid']) || $pr === null) {
            return $this->redirect('/error/state');
        }
        $target = $this->repo()->user((int) $fed['uid']);
        if ($req->input('password') !== LoginController::DEMO_PASSWORD) {
            return $this->view('auth/confirm_link', ['title' => 'Konfirmasi tautan akun', 'provider' => $pr, 'target' => $target, 'error' => 'Kata sandi salah.'], 'auth', 422);
        }
        $target['identities'] = array_values(array_unique(array_merge($target['identities'], [$pr['slug']])));
        $_SESSION['ovr'][(int) $target['id']]['identities'] = $target['identities'];
        unset($_SESSION['fed']);
        flash($pr['name'] . ' berhasil ditautkan ke akun Anda.');
        return $this->afterAuth($target, '/account/linked');
    }

    public function emailForm(Request $req, array $p): Response
    {
        $pr = $this->repo()->provider($p['slug']);
        if (!isset($_SESSION['fed']) || $pr === null) {
            return $this->redirect('/error/state');
        }
        return $this->view('auth/complete_email', ['title' => 'Lengkapi email', 'provider' => $pr, 'error' => null]);
    }

    public function emailSubmit(Request $req, array $p): Response
    {
        $pr = $this->repo()->provider($p['slug']);
        if (!isset($_SESSION['fed']) || $pr === null) {
            return $this->redirect('/error/state');
        }
        $email = $req->input('email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->view('auth/complete_email', ['title' => 'Lengkapi email', 'provider' => $pr, 'error' => 'Masukkan alamat email yang valid.'], 'auth', 422);
        }
        $_SESSION['fed']['email'] = $email;
        return $this->view('auth/verify_email', ['title' => 'Cek email Anda', 'provider' => $pr, 'email' => $email]);
    }

    /** Simulasi klik tautan verifikasi di email. */
    public function verify(Request $req, array $p): Response
    {
        $pr = $this->repo()->provider($p['slug']);
        $email = $_SESSION['fed']['email'] ?? null;
        if ($pr === null || !is_string($email)) {
            return $this->redirect('/error/state');
        }
        unset($_SESSION['fed']);
        return $this->createNew($pr, 'Pengguna ' . $pr['name'], $email);
    }
}
