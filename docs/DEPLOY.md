<!-- maks. 200 baris · pemilik: orchestrator (F9) -->
# Panduan Deploy BikininSSO (shared hosting / cPanel)

Prasyarat: PHP >= 8.2 (ekstensi sodium, openssl, pdo_mysql, curl, mbstring), MariaDB 10.5+ (InnoDB, utf8mb4), HTTPS aktif untuk domain SSO, akses SSH atau minimal cron cPanel. Contoh: akun `/home/<akun>/`, domain `sso.contoh.id`.

## 1. Tata letak dan webroot

- Folder `src/sso/` diunggah ke `/home/<akun>/sso/` (di LUAR webroot). Isi `src/public_html/` menjadi webroot domain.
- Webroot HANYA berisi `public_html/index.php`, `.htaccess`, dan `assets/`. Jangan pernah mengarahkan webroot ke `sso/`: di sana ada `config/`, `keys/`, `storage/`, dan `vendor/`.
- `public_html/index.php` memuat `../sso/bootstrap.php`; sesuaikan path bila struktur berbeda. Jalankan `composer install --no-dev --optimize-autoloader` di `sso/` (di lokal lalu unggah `vendor/` bila server tanpa Composer).
- `.htaccess` memaksa HTTPS, meneruskan header `Authorization`, dan merewrite semua request ke `index.php`. Pastikan `AllowOverride` aktif (default cPanel).

## 2. Konfigurasi dan rahasia

**Direkomendasikan: satu berkas `sso/.env`.** Salin `sso/.env.example` menjadi `sso/.env`, isi, lalu `chmod 600 .env`. Berkas ini berada di folder `sso/` (di luar webroot, jangan pernah di `public_html/`), tidak di-commit, dan tidak boleh masuk repo, tiket, atau log. Uji bahwa `.env` tidak dapat diakses lewat web.

Kunci: `APP_BASE_URL` (https publik, untuk tautan email dan issuer; bukan dari header Host), `APP_DEBUG` (HARUS `false`), `MAIL_FROM`, `KEY_DIR` (opsional), `TRUSTED_PROXIES` (dipisah koma), `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `HMAC_KEY`, `ENCRYPTION_KEY_CURRENT` (mis. `v1`), `ENCRYPTION_KEYS` (format `v1:base64,v2:base64`), serta opsional `BSSO_INSTALL_TOKEN` dan `BSSO_ADMIN_PASSWORD`. Buat nilai acak 32 byte di server: `php -r "echo base64_encode(random_bytes(32)),PHP_EOL;"` (satu untuk `HMAC_KEY`, satu untuk `v1`). Rotasi kunci: tambahkan `v2:...` ke `ENCRYPTION_KEYS`, ubah `ENCRYPTION_KEY_CURRENT=v2`, jalankan `bin/reencrypt.php`.

**Alternatif: `sso/config/*.php`** (rujuk `*.example.php`: `database.php`, `app.php`, `secrets.php`; `chmod 600`). Semua berkas config bersifat opsional.

Prioritas, digabung per kunci (satu kunci di env tidak menghapus kunci lain dari file):

| Urutan | Sumber |
| --- | --- |
| 1 (tertinggi) | environment server (mis. env PHP-FPM/vhost) |
| 2 | `sso/.env` |
| 3 | `sso/config/*.php` |
| 4 | default bawaan (mis. DB `127.0.0.1:3306`, nama `bikinin_sso`) |

Nilai kosong (`KEY=`) dianggap tidak di-set dan tidak menimpa sumber di bawahnya. Tanpa `HMAC_KEY` dan keyring (kunci `current` harus ada di daftar), aplikasi berhenti dengan error (fail-closed).

### 2a. Proxy tepercaya (`trusted_proxies`)

IP klien dipakai untuk rate limit dan audit. Default `[]`: hanya `REMOTE_ADDR`, header `X-Forwarded-For` (XFF) diabaikan. Isi hanya bila aplikasi berada di belakang proxy/CDN; jika tidak, klien bisa memalsukan XFF. Daftar berisi IP atau CIDR (IPv4/IPv6); entri tidak valid diabaikan. XFF dipakai hanya bila `REMOTE_ADDR` ada di daftar; dibaca dari kanan, hop tepercaya dilewati, alamat valid pertama yang bukan proxy dipakai; XFF rusak kembali ke `REMOTE_ADDR`. `X-Real-IP` tidak dipakai.

- cPanel tanpa proxy: biarkan `[]`.
- Reverse proxy sendiri (nginx/Apache di host yang sama): `['127.0.0.1', '::1']`; nginx wajib `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`.
- Cloudflare: isi seluruh rentang di https://www.cloudflare.com/ips/ (mis. `'173.245.48.0/20'`, `'2400:cb00::/32'`), dan pastikan origin hanya menerima trafik dari rentang itu.

## 3. Izin direktori

- `keys/` (private key RS256, dibuat otomatis oleh `KeyManager`): direktori `700`, berkas kunci `600`. Pemilik = user PHP/akun hosting. Verifikasi: `ls -ld keys; ls -l keys`.
- `storage/` (logs, cache): `700` dan dapat ditulis user PHP. Log tidak boleh dapat diunduh lewat web (sudah di luar webroot).
- Tidak ada berkas di `sso/` yang boleh `777`.

## 4. Database dan migrasi

1. Buat DB kosong dan user khusus (hak SELECT/INSERT/UPDATE/DELETE, CREATE/ALTER hanya saat migrasi bila memungkinkan).
2. `cd sso && php bin/migrate.php` (idempoten, dicatat di `schema_migrations`; seed provider diisi bila tabel kosong).
3. DB dev/tes lama dari fase pengembangan HARUS dibuat ulang (drop lalu migrasi dari nol): skema berubah selama build dan tidak ada migrasi penambal. Jangan memakai data dev di produksi.

## 5. Superadmin pertama (/install)

Pilih satu:
- SSH: `php bin/create-admin.php --email=admin@contoh.id` (kata sandi lewat prompt atau env `BSSO_ADMIN_PASSWORD`).
- Tanpa SSH: set token lalu buka `https://sso.contoh.id/install`. Halaman aktif selama tabel `admin_roles` kosong dan `storage/install.lock` belum ada.

`/install` fail-closed: tanpa env `BSSO_INSTALL_TOKEN` (tidak diset/kosong) GET dan POST `/install` selalu 404. Untuk mengaktifkannya, set `BSSO_INSTALL_TOKEN` (nilai acak panjang) lalu isi token di form (atau header `X-Install-Token`; jangan lewat query string). Token salah juga 404. Cara set di cPanel: `SetEnv BSSO_INSTALL_TOKEN ...` di `.htaccess` sementara, atau variabel lingkungan PHP-FPM; hapus setelah instalasi. Jalur yang disarankan tanpa token: `bin/create-admin.php` lewat SSH. Login pertama: aktifkan MFA (wajib untuk area admin).

## 6. Cron

Satu entri tiap menit (retry logout back-channel, rotasi kunci terjadwal, GC):

```
* * * * * /usr/local/bin/php /home/<akun>/sso/bin/cron.php >> /dev/null 2>&1
```

`cron.php` memakai lock (`storage/cron.lock`) sehingga aman bila tumpang tindih. Hosting tanpa cron: pakai halaman admin Pemeliharaan (`/admin/maintenance`) untuk GC, retry logout, dan rotasi terjadwal secara berkala. GC menghapus data kedaluwarsa (auth_codes, federation_states, refresh_tokens/sso_sessions +7 hari, login_attempts +30 hari, audit_logs > 1 tahun).

## 7. HTTPS dan HSTS

HTTPS wajib. Aplikasi mengirim `Strict-Transport-Security` (max-age 1 tahun, includeSubDomains), CSP ketat, dan `.htaccess` mengalihkan HTTP ke HTTPS. Pasang sertifikat (AutoSSL/Let's Encrypt) dulu, uji, baru buka ke pengguna. Karena HSTS bersifat includeSubDomains, pastikan semua subdomain siap HTTPS. Di balik proxy, teruskan `X-Forwarded-Proto`.

## 8. Kunci penandatangan dan backup

- Kunci RS256 dibuat otomatis saat cron/rotasi pertama; rotasi otomatis tiap 90 hari (tumpang tindih 2 hari). Rotasi darurat: halaman admin Kunci (ketik `ROTATE`) atau `php bin/rotate-key.php --emergency`; semua token lama gugur.
- BACKUP terenkripsi di luar server: folder `keys/`, `.env` (atau `config/secrets.php`), dan dump DB. Kehilangan `.env`/`secrets.php` (keyring) membuat rahasia terenkripsi di DB (secret provider, TOTP, kunci Apple) tidak terbaca. Kehilangan `keys/` menggugurkan semua token; kunci baru dibuat otomatis tetapi sesi klien harus login ulang.
- Rotasi keyring (enkripsi ulang) dengan `bin/reencrypt.php`: (1) tambah `keyring.keys.v2` di `secrets.php` (kunci baru 32 byte base64), JANGAN hapus v1; (2) set `keyring.current` = `v2`; (3) backup DB lalu `php bin/reencrypt.php --dry-run` (hanya hitungan); (4) `php bin/reencrypt.php` (idempoten, aman diulang; exit non-nol bila ada baris gagal, dilaporkan sebagai `tabel.kolom id=N` tanpa isi); (5) verifikasi: jalankan lagi dan pastikan `diubah=0 gagal=0`, uji login MFA dan provider; (6) baru hapus v1 dari keyring. Kolom yang dicakup: `users.mfa_secret_enc`, `identity_providers.client_secret_enc` (termasuk kunci .p8 Apple). Data sesi pendek (PendingActionStore, mfa_setup/mfa_codes di sesi UI) sengaja tidak dicakup: berumur pendek, kedaluwarsa sendiri; selama v1 masih ada di keyring tetap terbaca, setelah v1 dihapus pengguna cukup mengulang aksi tersebut.
- `hmac_key` TIDAK dirotasi oleh alat ini (HMAC adalah hash satu arah, tidak bisa dienkripsi ulang). Mengganti `hmac_key` membuat semua nilai yang sudah di-HMAC tidak cocok lagi; ganti hanya bila bocor. Dampak: semua kode cadangan MFA (disimpan sebagai HMAC) tidak lagi valid; pengguna harus membuat ulang kode cadangan (atau admin mereset MFA).

## 9. Provider eksternal (termasuk Apple)

Daftarkan callback `https://sso.contoh.id/federation/<slug>/callback` di setiap provider. Isi client id/secret lewat `/admin/providers` (disimpan terenkripsi). Sign in with Apple: kunci `.p8` ditempel sebagai teks PEM di form provider (bukan diunggah ke `keys/`); disimpan terenkripsi di DB dan ikut backup DB + keyring. Simpan juga berkas `.p8` asli di tempat aman; Apple hanya mengizinkan unduh sekali. Team ID dan Key ID diisi di `extra_config` form.

## 10. Daftar periksa go-live

- [ ] `debug=false`, webroot hanya `public_html`, `config/*` dan `keys/` tidak dapat diakses lewat web (uji `https://sso.contoh.id/../sso/config/app.php` dan `/keys/`)
- [ ] izin `keys/` 700/600, `.env` dan `config/secrets.php` 600; `.env` tidak dapat diakses lewat web
- [ ] `BSSO_INSTALL_TOKEN` (bila dipakai) dihapus; `storage/install.lock` ada
- [ ] cron aktif (cek log dasbor admin: tidak ada notifikasi logout gagal)
- [ ] HTTPS valid, header HSTS/CSP terkirim (`curl -sI`)
- [ ] `/.well-known/openid-configuration` dan `/jwks` merespons
- [ ] backup keys + secrets + DB terjadwal
- [ ] email (`mail_from`) terkirim: uji pendaftaran dan lupa sandi
