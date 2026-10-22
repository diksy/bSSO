<!-- maks 80 baris -->
# Laporan Keamanan – BikininSSO (`src/`, branch main @ 4ec9133)

Tanggal: 2026-10-22 (pemindaian ulang setelah T12/T13) · Pemilik: security · Rujukan threat model: `docs/architecture.md` §4.

## 1. Cakupan dan pemindai
| Pemindai | Hasil |
| --- | --- |
| semgrep `--config auto` (awal: 227 berkas @ 66e924c) | 8 temuan, semuanya false positive (§3) |
| semgrep ulang (8 berkas T12/T13: `InstallController`, `InstallGuard`, `LoginController`, `FederationController`, `Admin/StepUpController`, `Account/MfaSetupController`, `Account/PasswordController`, `RateLimiter`) | 1 temuan: `unlink-use` di `InstallGuard.php:52`, false positive yang sudah dikenal |
| `composer audit` (`src/sso`) | 0 advisory |
| gitleaks detect (riwayat git, 16 commit) dan `--no-git` (working tree) | 0 kebocoran |
| OWASP ZAP baseline (DAST) | **BELUM DIJALANKAN**: ZAP dan MariaDB tidak tersedia, tidak ada URL staging. Wajib di staging sebelum rilis. |

Tinjauan manual awal: oauth-flow, federasi, admin + step-up, logout/SSRF, `/install`, sesi/cookie, CSRF, header, upload `.p8`, rate limit, escaping view. Tinjauan ulang dibatasi pada S1–S3.

## 2. Temuan
| ID | OWASP | Lokasi | Risiko | Temuan | Rekomendasi | Status |
| --- | --- | --- | --- | --- | --- | --- |
| S1 | A05 / A07 | `src/sso/src/Controller/InstallController.php:33-34,41-49,84-90` | tinggi | `/install` terbuka tanpa token, sehingga orang pertama yang mengaksesnya menjadi superadmin. | Fail-closed jika token kosong. | **diperbaiki** (T12): `requireEnabled()` mengembalikan 404 untuk GET dan POST jika `BSSO_INSTALL_TOKEN` kosong atau tidak diset. Token dibaca dari body/header (bukan query) dan dibandingkan dengan `hash_equals`. Token salah juga mendapat 404 yang seragam. Rate limit 10 tetap berlaku. |
| S2 | A07 | `src/sso/src/Controller/LoginController.php:60-63,80`; `src/sso/src/Controller/FederationController.php:256-266` | sedang | Race cek-lalu-catat pada login dan konfirmasi tautan federasi. | `begin()` atomik + `markSuccess()`. | **diperbaiki** (T13): percobaan dicatat sebagai gagal sebelum verifikasi Argon2 (`RateLimiter.php:54-65`, kondisi `> 5` setelah insert). Sukses ditandai hanya setelah password cocok. Akun suspended tetap tercatat gagal. Sisa pada IP dicatat sebagai S6. |
| S3 | A07 | `src/sso/src/Controller/Admin/StepUpController.php:77-87`; `src/sso/src/Controller/Account/MfaSetupController.php:75-87,148-157`; `src/sso/src/Controller/Account/PasswordController.php:57-67` | rendah | Race yang sama pada TOTP step-up, aktivasi/nonaktivasi MFA, dan cek password lama. | Samakan dengan `begin()`. | **diperbaiki** (T13): ketiga controller memanggil `begin()` sebelum verifikasi dan `markSuccess()` hanya setelah lolos. Tidak ditemukan jalur yang melewati limiter. |
| S4 | A04 / A07 | `src/sso/src/Security/RateLimiter.php:21-27` | rendah | Penguncian per email bersifat global, sehingga akun korban (termasuk superadmin) bisa dikunci terus-menerus (DoS login). | Kunci email+IP ditambah batas global yang lebih longgar, atau jeda progresif. | terbuka (diterima) |
| S5 | A05 | `src/sso/src/Http/Request.php:76-79`; `src/public_html/.htaccess:5-7` | rendah | `ip()` hanya memakai `REMOTE_ADDR`. Di balik proxy/CDN, batas per IP berlaku global dan IP di audit menjadi salah. | Tambahkan daftar proxy tepercaya (`mod_remoteip`/`trusted_proxies`). | terbuka (diterima) |
| S6 | A07 | `src/sso/src/Controller/LoginController.php:56`; `src/sso/src/Security/RateLimiter.php:21-27` | rendah | Sisa S2: batas **per IP** (20/15 menit) masih memakai `loginBlocked()` yang bersifat cek-lalu-catat. Request paralel ke banyak email berbeda dari satu IP bisa melampaui 20 (password spraying sesaat). Batas per email tetap atomik (5). | Tambahkan pengecekan IP setelah insert di `begin()` (misalnya `countFailuresByIp() > IP_LIMIT` lalu hapus baris). | terbuka (baru, non-blocking) |

Ringkasan status terbuka: kritis 0 · tinggi 0 · sedang 0 · rendah 3 (S4, S5, S6). Diperbaiki: S1, S2, S3.

## 3. False positive dan penanda allowlist (sudah diverifikasi di kode)
- semgrep `unlink-use` ×5: `InstallGuard.php:52` (path konstanta di `storage/`), `ProviderContext.php:176` (berkas `tempnam`), `KeyManager.php:259` (lewat `safePath()`), `KeyManager.php:285` dan `:317` (kid divalidasi regex). Tidak ada input pengguna.
- semgrep `symfony-non-literal-redirect` ×3 di `AuthorizeController.php:62,75,98`: `redirect_uri` dicocokkan persis dengan DB (`AuthorizeRequest.php:45`).
- `gitleaks:allow` ×3, semuanya sah: `ProviderConfig.php:86` (regex header PEM), `tests/Integration/AdminManageTest.php:543` (PEM dummy), `tests/Unit/AuthUnitTest.php:93` (string dummy). Allowlist `.gitleaks.toml` sempit (`condition = "AND"`).

## 4. Catatan regresi pemindaian ulang (tanpa temuan baru selain S6)
- Konkurensi `begin()`: insert lalu count (autocommit). Dua request serentak paling buruk sama-sama diblokir (fail-closed), tidak sama-sama lolos. Perilaku ini perlu dibuktikan pada MariaDB asli di staging (DB belum tersedia lokal).
- Baris yang ditolak dihapus (`delete($rowId)`), sehingga percobaan yang diblokir tidak memperpanjang penguncian. Ini wajar.
- Federasi: kunci `link:<id>` terpisah dari kunci email, sehingga per akun bisa ada 5 tebakan login + 5 tebakan tautan per 15 menit. Kegagalan tautan tetap ikut dihitung dalam kuota IP. Dapat diterima.
- `/install`: `hit()` masih cek-lalu-catat, tetapi dampaknya rendah karena token wajib dan lock `fopen 'x'` bersifat atomik. Saran (non-blocking): tolak token yang lebih pendek dari 32 karakter saat startup dan sebutkan di `DEPLOY.md`.

## 5. Kontrol yang terverifikasi baik (dari pemindaian awal, tidak berubah)
- OAuth: exact-match `redirect_uri`/`post_logout`, PKCE S256 wajib, code 60 detik sekali pakai (`FOR UPDATE`), reuse → family dicabut, `alg` dipin RS256 + `kid` dikenal, `typ` dibedakan.
- Federasi: state 256-bit terikat sesi dan sekali pakai, nonce + PKCE, `iss`/`aud`/`azp`/`exp` dicek, auto-link hanya bila verified + `trust_email`, user ber-MFA melewati `/login/mfa`.
- SSRF: `UrlAllowlist` + IP disematkan (`CURLOPT_RESOLVE`), tanpa redirect, batas 1 MiB.
- Admin: `AdminPolicy`, anti-eskalasi, superadmin terakhir dilindungi, step-up 5 menit dengan aksi tertunda terenkripsi yang hanya berlaku untuk rute `RequireRecentAuth`.
- Sesi/cookie `__Host-`, Secure, HttpOnly, SameSite=Lax, hash SHA-256, regenerasi setelah login/MFA/step-up. CSRF memakai `hash_equals`. CSP `default-src 'none'`, HSTS, nosniff, `no-store`. Semua view memakai `e()`.

## 6. Tindak lanjut
1. Jalankan ZAP baseline + authenticated scan di **staging** dengan MariaDB (DAST dan tes konkurensi limiter pada DB asli belum dilakukan).
2. S6: tambahkan pengecekan IP atomik di `begin()` (opsional sebelum rilis, non-blocking).
3. S4/S5 diterima sebagai risiko rendah. Tinjau ulang jika aplikasi dipasang di balik proxy/CDN.
4. Tambahkan tes konkurensi limiter ke suite Integration (jalankan pada MariaDB).
