<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sesi PHP native untuk status UI sementara (CSRF, flash, secret sekali tampil). Bukan status autentikasi.
 * Cookie `__Host-bsso_ui`; file di storage/sessions. Tes memakai fake() tanpa session_start().
 */
final class UiSession
{
    public const NAME = '__Host-bsso_ui';

    private static bool $fake = false;

    public static function fake(): void
    {
        self::$fake = true;
        $_SESSION = [];
    }

    public static function reset(): void
    {
        self::$fake = false;
        $_SESSION = [];
    }

    /** Mulai sesi (membuat bila belum ada). */
    public static function start(): void
    {
        if (self::$fake || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = (defined('SSO_ROOT') ? SSO_ROOT : dirname(__DIR__, 2)) . '/storage/sessions';
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
        session_name(self::NAME);
        session_save_path($path);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', '7200');
        session_start();
    }

    /** Sesi dianggap ada bila sudah aktif atau browser mengirim cookie-nya (tanpa membuat sesi baru). */
    public static function exists(): bool
    {
        return self::$fake || session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[self::NAME]);
    }

    /** Ganti id sesi (panggil saat login & MFA). */
    public static function regenerate(): void
    {
        self::start();
        if (!self::$fake) {
            session_regenerate_id(true);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::exists()) {
            return $default;
        }
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /** Ambil lalu hapus (sekali pakai). */
    public static function pull(string $key, mixed $default = null): mixed
    {
        if (!self::exists()) {
            return $default;
        }
        self::start();
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    public static function forget(string $key): void
    {
        if (self::exists()) {
            self::start();
            unset($_SESSION[$key]);
        }
    }
}
