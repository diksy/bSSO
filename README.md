# BikininSSO

Server SSO (OpenID Connect / OAuth 2.0) dan broker federasi, ditulis dengan PHP murni tanpa framework, dirancang agar bisa dipasang di shared hosting (cPanel) dengan MariaDB.

*[English version](README.en.md)*

> Status: rilis awal. Telah melewati review kode per modul, pemindaian statis, ZAP baseline, dan uji konkurensi di MariaDB. **Belum ada pentest oleh manusia.** Lakukan sendiri sebelum dipakai untuk data nyata (lihat [SECURITY.md](SECURITY.md)).

## Fitur

- OIDC Provider: authorization code + PKCE (S256 wajib), refresh token dengan rotasi dan deteksi pemakaian ulang, JWKS, discovery, userinfo, token RS256 dengan `kid`.
- Single logout: sesi SSO terpusat dan back-channel logout dengan perlindungan SSRF.
- Login lokal (email + kata sandi Argon2id), verifikasi email, reset kata sandi, MFA TOTP dengan kode cadangan, step-up untuk aksi admin.
- Federasi login: Google, Microsoft, GitHub, GitLab, Facebook, Apple, LinkedIn, Discord, X.
- Panel admin: pengguna, klien OAuth, provider, rotasi kunci, log audit, pemeliharaan.
- Pembatasan percobaan login atomik, header keamanan ketat (CSP, HSTS), `/install` fail-closed.
- Rotasi kunci penandatangan dan alat enkripsi ulang data (`bin/reencrypt.php`).

## Kebutuhan

PHP >= 8.2 (ekstensi sodium, openssl, pdo_mysql, curl, mbstring), MariaDB 10.5+ (InnoDB, utf8mb4), HTTPS untuk produksi.

## Mulai cepat

```bash
cd src/sso
composer install
# salin config/*.example.php menjadi config/*.php lalu isi (jangan di-commit)
php bin/migrate.php
php bin/create-admin.php --email=admin@contoh.id
```

Panduan lengkap pemasangan: [docs/DEPLOY.md](docs/DEPLOY.md). Integrasi aplikasi klien: [docs/CLIENT.md](docs/CLIENT.md). Panduan pengguna dan admin: [docs/user/](docs/user/README.md).

## Pengembangan

```bash
cd src/sso
composer test    # PHPUnit (SQLite in-memory, ~5 menit karena Argon2id)
composer stan    # PHPStan level 6
composer cs      # PHP_CodeSniffer (PSR-12)
```

Tes terhadap MariaDB nyata (opsional): atur `BSSO_TEST_DB=mariadb` serta `DB_USER`, `DB_PASS`, `DB_NAME` (nama DB wajib berakhiran `_test`; semua tabelnya dihapus setiap tes).

## Struktur repositori

| Folder | Isi |
| --- | --- |
| `src/sso/` | Aplikasi (di luar webroot): kode, view, migrasi, tes, skrip `bin/` |
| `src/public_html/` | Webroot: `index.php`, aset |
| `docs/` | Arsitektur, spesifikasi teknis ([requirements.md](docs/requirements.md)), keputusan, laporan keamanan, dokumentasi |
| `prototype/` | Prototype UI awal dengan data dummy (riwayat; bukan kode produksi) |

## Kontribusi dan keamanan

Lihat [CONTRIBUTING.md](CONTRIBUTING.md). Laporkan kerentanan secara privat sesuai [SECURITY.md](SECURITY.md), jangan lewat issue publik.

## Lisensi

[GNU Affero General Public License v3.0 atau lebih baru](LICENSE) (AGPL-3.0-or-later). Jika Anda menjalankan versi yang dimodifikasi sebagai layanan jaringan, Anda wajib menyediakan kode sumbernya kepada pengguna layanan tersebut.
