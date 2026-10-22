<!-- maks 80 baris -->
# Laporan Keamanan – BikininSSO (`src/`, branch main @ 4ec9133)

Tanggal: 2026-10-22 (pemindaian ulang DAST + konkurensi) · Pemilik: security · Rujukan threat model: `docs/architecture.md` §4.

## 1. Cakupan dan pemindai
| Pemindai | Hasil |
| --- | --- |
| semgrep `--config auto` (awal: 227 berkas @ 66e924c) | 8 temuan, semuanya false positive (§3) |
| semgrep ulang (8 berkas T12/T13) | 1 temuan: `unlink-use` di `InstallGuard.php:52`, false positive yang sudah dikenal |
| `composer audit` (`src/sso`) | 0 advisory |
| gitleaks detect (16 commit) dan `--no-git` (working tree) | 0 kebocoran |
| OWASP ZAP baseline (DAST) | Dijalankan orchestrator (docker, staging lokal): FAIL 0, WARN 9 (rendah/info), PASS 58. Lihat §4 |
| Uji konkurensi di MariaDB asli | Dijalankan orchestrator: lulus (§4) |

Tinjauan manual awal: oauth-flow, federasi, admin + step-up, logout/SSRF, `/install`, sesi/cookie, CSRF, header, upload `.p8`, rate limit, escaping view.

## 2. Temuan
| ID | OWASP | Lokasi | Risiko | Temuan | Rekomendasi | Status |
| --- | --- | --- | --- | --- | --- | --- |
| S1 | A05 / A07 | `src/sso/src/Controller/InstallController.php:33-34,41-49,84-90` | tinggi | `/install` terbuka tanpa token → orang pertama jadi superadmin. | Fail-closed jika token kosong. | **diperbaiki** (T12): `requireEnabled()` → 404 untuk GET/POST jika `BSSO_INSTALL_TOKEN` kosong/tak diset; token dari body/header + `hash_equals`; token salah juga 404; rate limit 10 tetap. |
| S2 | A07 | `src/sso/src/Controller/LoginController.php:60-63,80`; `FederationController.php:256-266` | sedang | Race cek-lalu-catat pada login & konfirmasi tautan federasi. | `begin()` atomik + `markSuccess()`. | **diperbaiki** (T13): dicatat gagal sebelum verifikasi Argon2 (`RateLimiter.php:54-65`, `> 5` setelah insert); sukses hanya setelah cocok. Sisa IP = S6. |
| S3 | A07 | `StepUpController.php:77-87`; `MfaSetupController.php:75-87,148-157`; `PasswordController.php:57-67` | rendah | Race sama pada TOTP step-up, MFA on/off, cek password lama. | Samakan dengan `begin()`. | **diperbaiki** (T13): ketiganya `begin()` sebelum verifikasi, `markSuccess()` hanya setelah lolos. |
| S4 | A04 / A07 | `src/sso/src/Security/RateLimiter.php:21-27` | rendah | Kunci per email global → akun korban bisa dikunci terus (DoS login). | Email+IP + batas global longgar, atau jeda progresif. | terbuka (diterima) |
| S5 | A05 | `src/sso/src/Http/Request.php:76-79`; `src/public_html/.htaccess:5-7` | rendah (DIPERBAIKI T15) | `ip()` hanya `REMOTE_ADDR`; di balik proxy batas per IP global & IP audit salah. | Daftar proxy tepercaya (`mod_remoteip`/`trusted_proxies`). | diperbaiki: set `trusted_proxies` di config/app.php bila di belakang proxy |
| S6 | A07 | `LoginController.php:56`; `RateLimiter.php:21-27` | rendah (DIPERBAIKI T14) | Batas per IP (20/15 mnt) masih cek-lalu-catat; paralel ke banyak email bisa > 20 (spraying sesaat). Batas per email tetap atomik (5). | Cek IP setelah insert di `begin()`. | terbuka (non-blocking) |

Ringkasan status terbuka: kritis 0 · tinggi 0 · sedang 0 · rendah 1 (S4). Diperbaiki: S1, S2, S3, S5 (T15, wajib diset di deploy berproxy), S6 (T14).

## 3. False positive (terverifikasi di kode)
- semgrep `unlink-use` ×5: `InstallGuard.php:52`, `ProviderContext.php:176`, `KeyManager.php:259,285,317` — path konstanta/`tempnam`/kid tervalidasi, tanpa input pengguna.
- semgrep `symfony-non-literal-redirect` ×3 `AuthorizeController.php:62,75,98`: `redirect_uri` exact-match DB (`AuthorizeRequest.php:45`).
- `gitleaks:allow` ×3 sah: `ProviderConfig.php:86` (regex PEM), `AdminManageTest.php:543`, `AuthUnitTest.php:93` (dummy). `.gitleaks.toml` sempit.

## 4. DAST + konkurensi (staging lokal, MariaDB 13.0.2)
Agen security terhalang `guard-bash.sh` (regex docker `ghcr.io/zaproxy/...`, flag ganda, curl/php); guard tidak diubah. Atas izin pemilik, orchestrator menjalankan langsung.
- **ZAP baseline** (`http://localhost:8080`, debug=false): FAIL 0. WARN 9, semua rendah/info: X-Powered-By (dari `php -S`, atur `expose_php=Off` di produksi), COOP/COEP tidak ada, CSP tanpa `form-action` (keputusan sadar, decisions.md), potongan kode PHP di halaman panduan (contoh, bukan sumber), 'Potential XSS' di `/login?return=` = false positive (diuji manual dengan payload `"><script>`: nilai tidak dipantulkan, jatuh ke `/account`). Cookie HttpOnly/Secure/SameSite, HSTS, anti-clickjacking: PASS.
- **Konkurensi login**: 40 POST /login paralel ke satu email (password salah) → 5 diproses (401), 35 ditolak (429). Batas 5 gagal/15 menit tegas di MariaDB (menutup S2).
- **Auth code paralel**: kode yang sama ditukar 10x serentak, 3 putaran → selalu 1x 200 + 9x 400 (single-use atomik).
- Belum teruji: authenticated scan ZAP, reuse refresh token paralel, `begin()` untuk jalur MFA/step-up/ganti password secara paralel, HTTPS nyata.


## 5. Kontrol terverifikasi baik (statik, tidak berubah)
- OAuth: exact-match URI, PKCE S256 wajib, code 60 dtk sekali pakai (`FOR UPDATE`), reuse → family dicabut, `alg` RS256 + `kid`, `typ` dibedakan.
- Federasi: state 256-bit terikat sesi sekali pakai, nonce + PKCE, `iss/aud/azp/exp` dicek, auto-link hanya verified + `trust_email`, MFA tetap.
- SSRF: `UrlAllowlist` + IP disematkan (`CURLOPT_RESOLVE`), tanpa redirect, batas 1 MiB.
- Admin: `AdminPolicy`, anti-eskalasi, superadmin terakhir dilindungi, step-up 5 menit, aksi tertunda terenkripsi.
- Sesi/cookie `__Host-` Secure/HttpOnly/Lax, hash SHA-256, regenerasi login/MFA/step-up. CSRF `hash_equals`. CSP `default-src 'none'`, HSTS, nosniff, `no-store`. Semua view `e()`.

## 6. Tindak lanjut
1. Opsional: perbaiki regex `guard-bash.sh` (image `ghcr.io/zaproxy/zaproxy:stable`, `--network host -v`) agar agen security bisa memindai sendiri; jalankan ZAP authenticated scan di staging HTTPS.
2. S6 selesai (T14, `RateLimiter::beginIp()`); tes konkurensi IP belum ada di suite.
3. S4 diterima sebagai risiko rendah. S5: isi `trusted_proxies` bila di belakang proxy/CDN (DEPLOY.md bagian 2a).
4. Tambahkan tes konkurensi limiter ke suite Integration (butuh MariaDB; skrip uji ad hoc sudah lulus).
