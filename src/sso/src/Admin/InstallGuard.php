<?php

declare(strict_types=1);

namespace App\Admin;

use App\Repository\AdminRoleRepository;

/** Penjaga /install: terbuka hanya selama tidak ada admin DAN berkas kunci belum ada (di storage/, di luar webroot). */
final class InstallGuard
{
    private readonly string $lockFile;

    public function __construct(private readonly AdminRoleRepository $roles, ?string $lockFile = null)
    {
        $this->lockFile = $lockFile ?? dirname(__DIR__, 2) . '/storage/install.lock';
    }

    /** admin_roles kosong DAN storage/install.lock belum ada. */
    public function open(): bool
    {
        return !is_file($this->lockFile) && $this->roles->count() === 0;
    }

    /**
     * Ambil kunci secara atomik (create-exclusive). Hanya satu pemanggil serentak yang berhasil.
     */
    public function acquire(): bool
    {
        if (!$this->open()) {
            return false;
        }
        $dir = dirname($this->lockFile);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $h = @fopen($this->lockFile, 'x');
        if ($h === false) {
            return false;
        }
        fwrite($h, gmdate('c') . "\n");
        fclose($h);
        @chmod($this->lockFile, 0600);

        return true;
    }

    /** Lepas kunci bila pemasangan gagal sebelum admin dibuat (agar bisa diulang). */
    public function release(): void
    {
        if (is_file($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }
}
