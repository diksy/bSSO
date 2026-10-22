<!-- maks 80 baris -->
# Laporan Keamanan – BikininSSO (`src/`, branch main @ 66e924c)

Tanggal: 2026-10-22 · Pemilik: security · Rujukan threat model: `docs/architecture.md` §4.

## 1. Cakupan dan pemindai
| Pemindai | Hasil |
| --- | --- |
| semgrep `--config auto` (227 berkas, tanpa `vendor/`, `tests/`) | 8 temuan, semuanya false positive (lihat §3) |
| `composer audit` (`src/sso`) | 0 advisory |
| gitleaks detect (riwayat git, 14 commit) | 0 kebocoran |
| gitleaks detect `--no-git` (working tree) | 0 kebocoran |
| OWASP ZAP baseline (DAST) | **DILEWATI**: ZAP dan MariaDB tidak tersedia, tidak ada URL staging. Wajib dijalankan di staging sebelum rilis. |

Tinjauan manual terarah: oauth-flow, federasi, admin + step-up, logout/SSRF, `/install`, sesi/cookie, CSRF, header keamanan, upload `.p8`, rate limit, escaping view.

## 2. Temuan
| ID | OWASP | Lokasi | Risiko | Temuan | Rekomendasi | Status |
| --- | --- | --- | --- | --- | --- | --- |
| S1 | A05 Security Misconfiguration / A07 | `src/sso/src/Controller/InstallController.php:42-45`, `src/sso/src/Admin/InstallGuard.php:20-23` | tinggi | Bila `BSSO_INSTALL_TOKEN` kosong/tidak diset, `/install` terbuka untuk siapa pun selama `admin_roles` kosong dan `install.lock` belum ada. Orang pertama yang mencapai URL (pemindai otomatis memburu `/install`) menjadi **superadmin**. Jendela sama terbuka lagi bila DB dibuat ulang dan `install.lock` terhapus. | Fail-closed: token kosong → 404 (atau hanya `bin/create-admin.php` via CLI). Wajibkan token acak ≥32 karakter, bandingkan dengan `hash_equals` (sudah ada). Sebutkan di `DEPLOY.md`. | terbuka |
| S2 | A07 Identification & Auth Failures | `src/sso/src/Controller/LoginController.php:56,63,66`; `src/sso/src/Controller/FederationController.php:256,260-261` | sedang | Pembatas login berpola cek-lalu-catat (`loginBlocked()` → verifikasi Argon2 → `record()`). Request paralel lolos pengecekan sebelum kegagalan pertama tercatat; verifikasi Argon2 yang lambat memperlebar jendela race sehingga batas 5/email dan 20/IP per 15 menit bisa dilampaui berkali lipat. Hal yang sama berlaku pada konfirmasi tautan federasi. | Pakai pola atomik `RateLimiter::begin()` + `markSuccess()` seperti `MfaController.php:75-85` (catat dulu, lalu hitung), untuk kunci email dan IP. | terbuka |
| S3 | A07 | `src/sso/src/Controller/Admin/StepUpController.php:77,82`; `src/sso/src/Controller/Account/MfaSetupController.php:71,82,144,152`; `src/sso/src/Controller/Account/PasswordController.php:54,63` | rendah | Race yang sama pada TOTP step-up, TOTP aktif/nonaktif MFA, dan cek password lama. Tebakan paralel melampaui batas 5/15 menit. Butuh sesi yang sudah dicuri, padahal step-up justru lapisan pertahanan untuk skenario itu. | Samakan dengan `begin()` atomik. Tambahkan tes konkurensi. | terbuka |
| S4 | A04 Insecure Design / A07 | `src/sso/src/Security/RateLimiter.php:21-27` | rendah | Penguncian per email bersifat global (5 gagal dari IP mana pun). Penyerang bisa terus mengunci akun korban, termasuk superadmin, tanpa batas (DoS login). | Kunci per pasangan email+IP ditambah batas email global yang lebih longgar, atau jeda progresif. Pertimbangkan pengecualian bagi login yang lolos dari IP/perangkat yang dikenal. | terbuka |
| S5 | A05 | `src/sso/src/Http/Request.php:76-79`; `src/public_html/.htaccess:5-7` | rendah | `ip()` hanya memakai `REMOTE_ADDR`, sedangkan `.htaccess` mengasumsikan reverse proxy (`X-Forwarded-Proto`). Di balik proxy/CDN semua klien berbagi satu IP: batas per IP (login 20, `/token` 120/menit, `/install` 10) menjadi global (DoS), dan IP di audit/sesi menjadi salah. | Tambahkan daftar proxy tepercaya di config (`mod_remoteip` atau `trusted_proxies`). Baca `X-Forwarded-For` hanya dari IP itu. | terbuka |

Ringkasan: kritis 0 · tinggi 1 · sedang 1 · rendah 3.

## 3. False positive dan penanda allowlist (sudah diverifikasi di kode)
- semgrep `unlink-use` ×5: `InstallGuard.php:52` (path konstanta di `storage/`), `ProviderContext.php:176` (berkas `tempnam`), `KeyManager.php:259` (lewat `safePath()`: harus berada langsung di `keyDir` dan `.pem`), `KeyManager.php:285` dan `:317` (path buatan sendiri dari kid yang divalidasi regex). Tidak ada input pengguna.
- semgrep `symfony-non-literal-redirect` ×3 di `AuthorizeController.php:62,75,98`: `redirect_uri` sudah dicocokkan persis dengan DB (`AuthorizeRequest.php:45`). URI tidak sah tidak pernah dipakai untuk redirect.
- `gitleaks:allow` ×3, semuanya sah: `ProviderConfig.php:86` (regex header PEM, bukan kunci), `tests/Integration/AdminManageTest.php:543` (PEM rusak dummy), `tests/Unit/AuthUnitTest.php:93` (string dummy uji penyaringan log). `.gitleaks.toml` membatasi allowlist dengan `condition = "AND"` (path `panduan-integrasi.md` DAN pola `eyJ…`): sempit dan wajar.

## 4. Kontrol yang terverifikasi baik (tanpa temuan)
- OAuth: exact-match `redirect_uri`/`post_logout`, PKCE S256 wajib, code 60 detik sekali pakai dengan `FOR UPDATE`, reuse code/refresh → family dicabut (+ sesi), status user/sesi dicek saat redeem/rotate/userinfo, `alg` dipin RS256 + `kid` dikenal, `typ` dibedakan (`at+jwt`/`JWT`/`logout+jwt`).
- Federasi: state 256-bit diikat ke sesi UI dan sekali pakai, nonce + PKCE, `iss`/`aud`/`azp`/`exp` dicek. Auto-link hanya bila `trust_email` + verified (Microsoft/Facebook/X dipaksa unverified, GitHub hanya primary+verified). User ber-MFA selalu melewati `/login/mfa`.
- SSRF: `UrlAllowlist` (https:443, blokir IP privat/loopback/metadata/IPv4-mapped, sufiks internal) + IP disematkan (`CURLOPT_RESOLVE`), tanpa redirect, batas 1 MiB. Endpoint GitHub emails dipatok di kode.
- Admin: matriks `AdminPolicy`, `canManageUser` (anti-eskalasi ke superadmin), superadmin terakhir dilindungi, step-up 5 menit dengan aksi tertunda terenkripsi yang terikat ke sid+uid dan hanya untuk rute ber-`RequireRecentAuth`, idle admin, redirect `back` hanya ke `/admin`.
- `.p8`: ≤4096 byte, PEM PKCS#8 murni, EC P-256 divalidasi OpenSSL, disimpan terenkripsi, write-only.
- Sesi/cookie: `__Host-`, Secure, HttpOnly, SameSite=Lax, hanya hash SHA-256 yang disimpan, id diganti setelah login/MFA/step-up, idle 8 jam / absolut 24 jam. CSRF per sesi UI dengan `hash_equals`.
- Header: CSP `default-src 'none'` (tanpa script), `frame-ancestors 'none'`, HSTS, nosniff, `Referrer-Policy: no-referrer`, `no-store` sebagai bawaan. Semua output view memakai `e()`. Markdown di-escape dulu dan hanya mengizinkan tautan http(s).

## 5. Penilaian `/install` saat `BSSO_INSTALL_TOKEN` kosong
Saat ini fail-open (S1). Lock atomik (`fopen 'x'`) mencegah race antar-penginstal, tetapi tidak mencegah orang asing menjadi penginstal pertama. **Usul fail-closed**: tanpa token, `/install` mengembalikan 404 dan admin pertama dibuat lewat `bin/create-admin.php` (CLI, SSH). Dengan token, alur web tetap seperti sekarang. Pertahankan `install.lock` meskipun DB dikosongkan.

## 6. Tindak lanjut
1. Perbaiki S1 dan S2 sebelum rilis, lalu jalankan pemindaian ulang terbatas pada `InstallController`, `LoginController`, `FederationController`, dan `RateLimiter`.
2. Jalankan ZAP baseline + authenticated scan di staging (DAST belum dilakukan).
3. Tambahkan tes konkurensi limiter (S2/S3) ke suite Integration.
