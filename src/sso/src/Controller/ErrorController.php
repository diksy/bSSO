<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class ErrorController
{
    /** kind => [status, judul, kode, teks] */
    private const KINDS = [
        'redirect_uri' => [400, 'redirect_uri tidak valid', 'invalid_request', 'Alamat tujuan (redirect_uri) tidak terdaftar untuk aplikasi ini. Anda tidak diarahkan kembali demi keamanan. Hubungi pengembang aplikasi yang Anda tuju.'],
        'client' => [401, 'Aplikasi tidak dikenal', 'invalid_client', 'Aplikasi yang meminta login tidak terdaftar atau sedang dinonaktifkan.'],
        'state' => [400, 'Sesi login federasi kedaluwarsa', 'invalid_state', 'Parameter state tidak cocok atau sudah kedaluwarsa. Silakan mulai login dari awal.'],
        'provider' => [502, 'Provider tidak dapat dihubungi', 'temporarily_unavailable', 'Kami gagal menyelesaikan login lewat provider eksternal. Coba lagi, atau masuk dengan email dan kata sandi.'],
        'rate' => [429, 'Terlalu banyak percobaan', 'rate_limited', 'Terlalu banyak percobaan masuk. Coba lagi beberapa menit lagi.'],
        'csrf' => [419, 'Formulir kedaluwarsa', 'csrf_invalid', 'Token keamanan formulir tidak valid. Muat ulang halaman lalu coba lagi.'],
        403 => [403, 'Akses ditolak', 'access_denied', 'Anda tidak memiliki hak untuk membuka halaman ini.'],
        404 => [404, 'Halaman tidak ditemukan', 'not_found', 'Halaman yang Anda cari tidak ada.'],
        405 => [405, 'Metode tidak diizinkan', 'method_not_allowed', 'Metode permintaan tidak didukung untuk halaman ini.'],
        500 => [500, 'Terjadi kesalahan', 'server_error', 'Terjadi kesalahan pada server. Coba lagi beberapa saat lagi.'],
    ];

    private const STATUS_KIND = [419 => 'csrf', 403 => 403, 404 => 404, 405 => 405, 429 => 'rate'];

    public function __construct(private readonly View $view)
    {
    }

    public function show(Request $request): Response
    {
        $kind = (string) $request->attribute('kind');

        return $this->render(self::KINDS[$kind] ?? self::KINDS[404]);
    }

    /** Dipakai Kernel untuk HttpException dan kegagalan tak terduga. */
    public function forStatus(int $status): Response
    {
        return $this->render(self::KINDS[self::STATUS_KIND[$status] ?? 500]);
    }

    /** @param array{0:int,1:string,2:string,3:string} $k */
    private function render(array $k): Response
    {
        return $this->view->render('auth/error', ['title' => $k[1], 'code' => $k[2], 'heading' => $k[1], 'text' => $k[3]], 'auth', $k[0]);
    }
}
