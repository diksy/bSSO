# Berkontribusi

Terima kasih sudah tertarik berkontribusi. / Thanks for your interest in contributing.

## Alur

1. Fork, lalu buat branch dari `main` (`modul/<nama>` atau `perbaikan/<nama>`).
2. Baca [docs/architecture.md](docs/architecture.md) dan [docs/requirements.md](docs/requirements.md) (rujukan normatif endpoint, skema, keamanan).
3. Jalankan di `src/sso`: `composer test`, `composer stan`, `composer cs`. Semuanya harus hijau.
4. Ajukan pull request dengan deskripsi singkat dan tes untuk perubahan perilaku.

## Aturan kode

- PHP 8.2+, `declare(strict_types=1)`, PSR-12, PHPStan level 6, tanpa framework.
- Query selalu berparameter; output view selalu di-escape; tidak ada rahasia di kode, log, atau commit.
- Perubahan pada auth, sesi, token, atau enkripsi wajib menyertakan tes keamanan (kasus penyalahgunaan, bukan hanya jalur normal) dan dijelaskan dampaknya pada PR.
- Jangan menambah dependensi tanpa alasan; lisensinya harus kompatibel dengan AGPL-3.0.
- Tes memakai SQLite in-memory; untuk perubahan SQL/transaksi jalankan juga dengan `BSSO_TEST_DB=mariadb` (lihat README).

## Lisensi kontribusi

Dengan mengirim kontribusi, Anda setuju kontribusi itu dilisensikan di bawah AGPL-3.0-or-later seperti proyek ini.

## Keamanan

Kerentanan dilaporkan secara privat, lihat [SECURITY.md](SECURITY.md).
