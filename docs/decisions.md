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
