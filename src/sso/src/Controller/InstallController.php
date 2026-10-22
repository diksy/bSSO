<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AdminCreator;
use App\Admin\InstallGuard;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\RateLimiter;
use App\Support\View;

/**
 * Pemasangan awal: hanya selama admin_roles kosong dan storage/install.lock belum ada. Kunci diambil atomik
 * sebelum admin dibuat (anti race).
 * Fail-closed: tanpa env BSSO_INSTALL_TOKEN (kosong/tidak diset) GET dan POST = 404. Bila diset, POST wajib
 * membawa token cocok (field form body atau header X-Install-Token; bukan query string); salah = 404 seragam.
 */
final class InstallController
{
    public function __construct(
        private readonly View $view,
        private readonly InstallGuard $guard,
        private readonly AdminCreator $creator,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function form(Request $request): Response
    {
        $this->requireOpen();
        $this->requireEnabled();

        return $this->render(false, null);
    }

    public function submit(Request $request): Response
    {
        $this->requireOpen();
        $this->requireEnabled();
        if (!$this->limiter->hit('install:' . $request->ip(), $request->ip(), 10)) {
            return $this->render(false, 'Terlalu banyak percobaan. Coba lagi nanti.', 429);
        }
        $given = $request->body['install_token'] ?? ($request->headers['x-install-token'] ?? '');
        if (!is_string($given) || !hash_equals((string) getenv('BSSO_INSTALL_TOKEN'), $given)) {
            throw new HttpException(404);
        }
        $password = (string) $request->input('password', '');
        if (!hash_equals($password, (string) $request->input('password2', ''))) {
            return $this->render(false, 'Kedua kata sandi tidak sama.', 422);
        }
        if (!$this->guard->acquire()) {
            throw new HttpException(404);
        }
        try {
            $this->creator->create((string) $request->input('email', ''), 'Superadmin', $password);
        } catch (\InvalidArgumentException $e) {
            $this->guard->release(); // validasi gagal: belum ada admin, boleh diulang
            return $this->render(false, $e->getMessage(), 422);
        } catch (\PDOException $e) {
            $this->guard->release(); // PDOException ⊂ RuntimeException: transaksi di-rollback, belum ada admin
            throw $e;
        } catch (\RuntimeException) {
            // Admin sudah ada (race) atau email sudah dipakai: kunci tetap, halaman tertutup.
            throw new HttpException(404);
        } catch (\Throwable $e) {
            $this->guard->release(); // kegagalan tak terduga (mis. DB): jangan tinggalkan install.lock tanpa admin
            throw $e;
        }

        return $this->render(true, null);
    }

    private function requireOpen(): void
    {
        if (!$this->guard->open()) {
            throw new HttpException(404);
        }
    }

    /** Tanpa token terkonfigurasi, /install dimatikan (404 tanpa alasan). */
    private function requireEnabled(): void
    {
        $t = getenv('BSSO_INSTALL_TOKEN');
        if (!is_string($t) || $t === '') {
            throw new HttpException(404);
        }
    }

    private function render(bool $done, ?string $error, int $status = 200): Response
    {
        return $this->view->render('auth/install', [
            'title' => 'Pasang BikininSSO', 'done' => $done, 'error' => $error,
            'needToken' => true,
        ], 'auth', $status);
    }
}
