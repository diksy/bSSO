<!-- satu baris per keputusan. Pemilik: siapa pun. Dibaca: architect, orchestrator -->
# Log Keputusan

| Tanggal | Keputusan | Alasan |
| --- | --- | --- |
| 2026-10-19 | Kode prototype di `prototype/`, kode final di `src/` | Hook dapat memblokir tulis ke `src/` sebelum gerbang `/approve` |
| 2026-10-21 | Nama produk di UI "BikininSSO" (logo teks) | Keputusan pemilik proyek |
| 2026-10-21 | UI, dokumentasi, dan komunikasi berbahasa Indonesia | Keputusan pemilik proyek |
| 2026-10-21 | UI minimalis dengan aksen keluarga merah redup (F10) | Keputusan pemilik proyek |
| 2026-10-21 | `requirements.md` dihapus setelah architect memindahkan isinya ke `architecture.md` | Detail teknis masih dibutuhkan sampai fase build |
| 2026-10-21 | Prototype mengikuti struktur `sso/` + `public_html/` (requirements §9) di dalam `prototype/` | Struktur folder harus mengikuti konvensi final |
| 2026-10-21 | Prototype: sesi dummy di $_SESSION; aksi admin disimulasikan dengan step-up TOTP nyata-alur (aksi tertunda dijalankan setelah verifikasi); view hanya lewat MockRepository | Mendemokan alur F8 tanpa DB/kripto
| 2026-10-22 | Panduan integrasi publik di `/panduan-integrasi` + versi Markdown `.md`; contoh kode curl + PHP | Keputusan pemilik proyek, ramah AI agent |
| 2026-10-22 | Gerbang dibuka: mode hybrid (pertahankan views/, app.css, konten panduan; logika ditulis ulang) | Dikonfirmasi pemilik proyek |
| 2026-10-22 | Hook mendukung PHP: php -l + cek strict_types (edit), phpcs/phpcbf PSR-12, phpstan & phpunit (selesai builder), composer audit (commit) jika tersedia di vendor/bin | Stack final PHP tanpa framework |
| 2026-10-22 | Dev dependency disetujui: phpstan/phpstan, squizlabs/php_codesniffer (selain PHPUnit) | Disetujui pemilik proyek; tidak ikut ke produksi |
| 2026-10-22 | Build dibagi 12 modul (0 kontrak … 11 pengerasan), urutan & kesulitan di architecture §2; modul 0 = semua stub/kontrak | Kontrak dibuat lebih dulu; M4/M7 dipecah agar diff kecil |
| 2026-10-22 | `requirements.md` dipertahankan sebagai rujukan normatif sampai modul 11 selesai (menggantikan keputusan 2026-10-21) | architecture.md maks 120 baris tak muat skema/kriteria; spec F1–F9 merujuk §11 |
| 2026-10-22 | Views memakai struktur prototype (`views/layout|auth|account|admin|partials`), bukan nama datar §9 | Batasan APPROVED: pertahankan views/ |
| 2026-10-22 | Sesi PHP native `__Host-bsso_ui` hanya untuk status UI (CSRF, flash, authorize tertunda, pra-MFA); autentikasi hanya di `sso_sessions` | Paling sederhana di shared hosting; §10 tidak mengatur penyimpanan CSRF |
| 2026-10-22 | Migrasi `002_mfa.sql`: kolom `users.mfa_backup_codes` (JSON HMAC) dan `users.mfa_last_step` | §4 tidak punya tempat kode cadangan & anti-replay TOTP; jumlah tabel tetap 16 |
| 2026-10-22 | Token verifikasi email & reset password stateless HMAC (`secrets.php` `hmac_key`), reset terikat hash password saat ini | Tanpa tabel tambahan |
| 2026-10-22 | `SessionTerminator` interface: modul 2 pakai implementasi lokal, modul 6 menggantinya dengan `LogoutService` | M2 butuh pencabutan sesi sebelum back-channel ada |
| 2026-10-22 | QR MFA: usulan library `chillerlan/php-qrcode`, MENUNGGU persetujuan pemilik; sementara kunci manual + `otpauth://` | Library di luar firebase/php-jwt wajib disetujui pemilik |
| 2026-10-22 | Library QR MFA: chillerlan/php-qrcode disetujui (T3) | Disetujui pemilik via Telegram |
| 2026-10-22 | requirements.md dipertahankan sampai T11 selesai | Disetujui pemilik via Telegram; architecture.md dibatasi 120 baris |
| 2026-10-22 | Revisi kontrak T0: `/admin/maintenance/rotate-keys` dipertahankan, hanya `KeyManager::rotateIfDue()` (idempoten) + `RequireRecentAuth`; operator boleh | §8 Pemeliharaan "rotasi kunci terjadwal" untuk operator; "semua aksi kunci" wajib step-up; rotasi paksa tetap khusus `keys:manage` |
| 2026-10-22 | Aksi admin users `status`, `reset-mfa`, `force-reset`, `unlink/{slug}` wajib `RequireRecentAuth`; cabut sesi, toggle, gc, retry-logout tidak | Mengubah faktor autentikasi/akses user = berisiko (F8); aksi defensif harus cepat |
| 2026-10-22 | POST `/federation/{slug}/callback` (Apple form_post) dikecualikan dari Csrf, handler `formPostCallback` hanya menyimpan `user` ke state lalu 303 ke GET callback; CSRF = state hash terikat sesi UI + nonce + PKCE | Cookie SameSite=Lax tidak terkirim pada POST lintas situs; satu jalur pemrosesan |
| 2026-10-22 | Middleware rute: `class-string` atau `[class, ...args]`, di-resolve `Container::make` (dependensi dulu, argumen rute terakhir); `AdminPolicy::MATRIX` konstanta publik | Kontrak builder T0 jelas tanpa service locator di middleware |
| 2026-10-22 | firebase/php-jwt dinaikkan ke ^7.0 | composer audit (CVE-2025-45769, <7.0.0) memblokir commit T0; belum dipakai kode, tes tetap lulus |
| 2026-10-22 | Revisi kontrak T2: tabel lain merujuk sesi lewat `sso_sessions.sid` (stabil, FK ON DELETE CASCADE), bukan `id_hash` yang dirotasi; kolom `session_id_hash` → `session_sid` di `session_clients`/`auth_codes`/`refresh_tokens`; ubah langsung `001_init.sql`; dikerjakan sebagai commit pertama T3 | FK ke `id_hash` memblokir `replaceIdHash` (MFA/step-up) dan `auth_codes`/`refresh_tokens` dangling; ON UPDATE CASCADE tidak menolong kolom tanpa FK dan menyebar rotasi ke banyak tabel; belum ada produksi |
| 2026-10-22 | CSP tanpa `form-action` (default-src none, tanpa inline script, frame-ancestors none) | Redirect login ke klien tidak boleh diblokir browser; risiko diterima (review T1) |
| 2026-10-22 | Kunci .p8 Apple: ditempel sebagai teks PEM (bukan upload berkas) lalu divalidasi (maks. 4 KB, PKCS#8, EC P-256) dan disimpan terenkripsi (Crypto) di `identity_providers.client_secret_enc`, bukan di `keys/` | Menyimpan di DB menjaga satu jalur rahasia, ikut rotasi keyring (`bin/reencrypt.php`), dan body POST dapat diputar ulang oleh step-up (berkas multipart tidak); menyimpang dari requirements §8 |
| 2026-10-22 | `extra_config` provider memakai daftar putih kunci per slug (apple: team_id,key_id; facebook: graph_version; x: token_auth); `emails_url` GitHub dihapus dan GitHubDriver memakai konstanta api.github.com | Mencegah Bearer token dikirim ke host pilihan admin |
| 2026-10-22 | Facebook: `extra_config.graph_version` (bawaan v23.0, perlu diverifikasi dokumentasi Meta) menentukan URL authorize/token/userinfo yang ditulis ulang saat simpan | Versi Graph API eksplisit sesuai requirements §5 |
| 2026-10-22 | "Paksa reset password" = kirim tautan reset (berlaku 1 jam) + cabut semua sesi; password lama tidak dihapus | Menghindari user terkunci bila email gagal; token terikat hash password sehingga sekali pakai |
| 2026-10-22 | Redirect URI `http://localhost`/`127.0.0.1` hanya diterima saat `config/app.php debug=true`; produksi wajib https | Requirements: localhost hanya untuk dev |
| 2026-10-22 | /install fail-closed: tanpa BSSO_INSTALL_TOKEN → 404; admin pertama via bin/create-admin.php (S1) | Disetujui pemilik via Telegram (✅ atas usulan security) |
| 2026-10-22 | Tes MariaDB opt-in: `BSSO_TEST_DB=mariadb` + `DB_*` env (DB_NAME wajib berakhiran `_test`); skema asli dari migrations. Hasil MariaDB 13.0.2: migrasi 001+002 OK & idempoten, 350/350 tes lulus (~16 mnt) setelah 3 fixture tes diperbaiki | Pemilik minta verifikasi di MariaDB dev; SQLite longgar menyembunyikan NOT NULL/FK |
| 2026-10-22 | Rilis disetujui pemilik via Telegram; spec.md & prototype-feedback.md diarsipkan. Apple .p8 tetap terenkripsi di DB (default) | Jawaban pemilik |
| 2026-10-22 | IP klien: X-Forwarded-For hanya dipercaya dari `trusted_proxies` (config/app.php, default kosong), diproses dari kanan, fail-closed; satu sumber ClientIp untuk Request dan Audit (T15, S5) | Disetujui pemilik via Telegram |
| 2026-10-22 | bin/reencrypt.php diimplementasi (Reencryptor): hanya kolom *_enc persisten; hmac_key tidak dirotasi (kode cadangan MFA jadi tidak valid bila diganti) (T16) | Disetujui pemilik via Telegram |
| 2026-10-22 | Proyek dibuka sebagai open source dengan lisensi AGPL-3.0-or-later; requirements.md dipindah ke docs/requirements.md; prototype/ disertakan; README ID+EN | Keputusan pemilik via Telegram |
| 2026-10-23 | Konfigurasi terpusat via `src/sso/.env` (opsional): prioritas env server > `.env` > `config/*.php` > default, digabung per kunci; `config/*.php` jadi opsional; parser sendiri tanpa dependency; keyring via `ENCRYPTION_KEY_CURRENT` + `ENCRYPTION_KEYS=v1:b64,v2:b64`; tanpa hmac_key/keyring tetap fail-closed (T17) | Disetujui pemilik via Telegram |
