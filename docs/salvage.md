<!-- maks 60 baris -->
# Audit Salvage – prototype/ → src/ (mode hybrid)
Batasan `APPROVED.md`: pertahankan `views/` (struktur HTML & teks UI), `app.css`, konten panduan; semua logika ditulis ulang. Simulasi (pemilih role, simulasi step-up, `/demo`, `mocks/`) tidak dibawa.

| Bagian prototype | Keputusan | Alasan / tindakan |
| --- | --- | --- |
| `public_html/assets/css/app.css` | pertahankan | Disetujui; satu stylesheet + token warna F10. Hapus hanya selektor `.demo-bar`, `.demo-hint` jika tak terpakai |
| `public_html/assets/img/favicon.svg` | pertahankan | Aset statis tanpa logika |
| `public_html/assets/img/qr-placeholder.svg` | tulis ulang (hapus) | Diganti QR asli / kunci manual (lihat architecture §5) |
| `public_html/index.php`, `.htaccess` | tulis ulang | Logika; harus sesuai §9 (require bootstrap, rewrite, HTTPS) |
| `sso/content/panduan-integrasi.md` | pertahankan | Disetujui. Nilai `*_DUMMY` adalah placeholder contoh, bukan rahasia; base URL tetap `sso.contoh.test` sampai domain final |
| `sso/views/layout/base.php`, `plain.php`, `account.php` | pertahankan | HTML sudah bebas inline script/style |
| `sso/views/layout/auth.php`, `partials/footer.php` | refactor | Hapus tautan "Peta prototype" (`/demo`) dan teks "Prototype dengan data dummy" |
| `sso/views/layout/admin.php` | refactor | Hapus blok `.demo-bar` (role-switch, simulasi 5 menit); `$_SESSION['one_time_secret']` diganti variabel `$oneTimeSecret`; variabel `$nav/$perm/$role/$module` dari `AdminPolicy` |
| `sso/views/partials/flashes.php`, `stepup_note.php` | refactor | Terima `$flashes` dari View, tanpa akses `$_SESSION` |
| `sso/views/auth/login.php` | refactor | Hapus `<details class="demo-hint">` (akun & kata sandi demo) |
| `sso/views/auth/mfa.php`, `admin/stepup.php`, `auth/confirm_link.php` | refactor | Hapus petunjuk kode/kata sandi demo |
| `sso/views/auth/error.php`, `logged_out.php`, `logout_confirm.php`, `complete_email.php`, `install.php`, `guide.php` | pertahankan (sedikit refactor) | Teks UI dipakai; `install.php` hapus kata "(simulasi)"; `guide.php` hapus tautan `/demo` |
| `sso/views/auth/verify_email.php` | refactor | Ganti tombol simulasi dengan teks "cek email Anda"; verifikasi lewat tautan HMAC |
| `sso/views/auth/federation_start.php`, `auth/map.php` | buang | Halaman simulasi provider & peta prototype; `/federation/{slug}/start` langsung redirect |
| `sso/views/account/*` (profile, password, mfa, linked, sessions) | refactor | Struktur/teks dipertahankan; data dari DTO; `mfa.php` pakai QR/`otpauth` asli, hapus "(dummy)"; `profile.php` ganti hint "di prototype" |
| `sso/views/admin/*` (dashboard, clients, client_form, providers, provider_form, users, user_detail, keys, audit, maintenance, settings, roles, forbidden, mfa_required) | refactor | Struktur/teks dipertahankan; `dashboard.php` hapus kalimat "pemilih demo"; field secret tetap kosong (write-only) |
| `sso/routes.php` | tulis ulang | Tambah middleware per rute, callback GET+POST, verifikasi email & reset password; buang `/demo`, `/admin/role-switch`, `/admin/expire-stepup` |
| `sso/bootstrap.php` | tulis ulang | Prototype `display_errors=1`, tanpa container/config/error log |
| `sso/src/Http/*` (Router, Request, Response, Kernel) | tulis ulang | Batasan APPROVED; kontrak baru di architecture §3 (middleware, 404/405, cookie) |
| `sso/src/Controller/*` (Login, Federation, Logout, Install, Account, Admin, Home, Error, Base) | tulis ulang | Auth & data pribadi wajib ditulis ulang; berisi kata sandi/TOTP demo hard-coded, `uniqid`, `$_SESSION` sebagai sesi SSO |
| `sso/src/Support/View.php` | tulis ulang | Pola render 3 lapis (view → layout → base) boleh ditiru, kode baru |
| `sso/src/Support/helpers.php` | tulis ulang | `e()` dan `asset()` dipertahankan nama & perilakunya; `csrf_*`/`flash` pindah ke `Csrf`/`Flash` |
| `sso/src/Support/Markdown.php` | tulis ulang | Batasan APPROVED; wajib escape-first dan hanya link http(s) |
| `sso/src/Repository/MockRepository.php` | buang | Diganti 16 repository PDO |
| `prototype/mocks/*` | buang | Data dummy; tidak boleh masuk `src/` maupun seed |
