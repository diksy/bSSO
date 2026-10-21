<!-- maks 120 baris -->
# Arsitektur – BikininSSO (mode hybrid)
Rujukan normatif detail: `requirements.md` §2–§11 (endpoint, alur token, skema §4, driver §5, logout §6, kunci §7, admin §8, keamanan §10, kriteria M1–M8 §11). Dokumen ini hanya menambah keputusan, urutan, dan kontrak. Salvage: `docs/salvage.md`.

## 1. Stack final
- PHP 8.2+, `declare(strict_types=1)`, PSR-4 `App\` → `sso/src/`, PSR-12, class `final` kecuali abstrak. Tanpa framework. MariaDB 10.5+ InnoDB utf8mb4.
- Produksi: `firebase/php-jwt` saja. Dev: phpunit, phpstan (level 6), php_codesniffer. Ekstensi: pdo_mysql, openssl, sodium, curl, mbstring, json.
- Tata letak: `src/sso/` (composer root, `vendor/`, `bin/`, `tests/Unit|Integration`, `content/panduan-integrasi.md`) + `src/public_html/` (`index.php`, `.htaccess`, `assets/`). Dev: `php -S localhost:8000 -t src/public_html`.
- Views mengikuti struktur prototype (`views/layout/`, `auth/`, `account/`, `admin/`, `partials/`), bukan nama datar §9.
- Status autentikasi HANYA di `sso_sessions` + cookie `__Host-bsso` (32 byte acak, DB menyimpan SHA-256). Status UI sementara (CSRF, flash, parameter `/authorize` tertunda, user pra-MFA, secret sekali tampil) di sesi PHP native `__Host-bsso_ui` (file di `storage/sessions`, di-regenerate saat login & MFA). View TIDAK membaca `$_SESSION`.
- Migrasi: `001_init.sql` = 16 tabel §4 apa adanya; `002_mfa.sql` = `ALTER TABLE users ADD mfa_backup_codes JSON NULL, ADD mfa_last_step BIGINT NULL`. Seed `providers.sql`: 9 baris nonaktif.
- Token verifikasi email (24 jam) dan reset password (1 jam): stateless, HMAC-SHA256 dengan `secrets.php['hmac_key']`; reset mengikat SHA-256 dari `password_hash` saat ini (otomatis sekali pakai). Kode cadangan MFA disimpan sebagai HMAC.
- Rate limit `/token` (60/klien/menit) memakai `login_attempts` dengan identifier `token:<client_id>`; TOTP memakai `mfa:<user_id>` (5 gagal/15 menit).
- Email: interface `Mailer` dengan implementasi `mail()`; SMTP menyusul jika pemilik memutuskan.
- Pembayaran: tidak ada dalam cakupan (spec). Tidak ada data kartu, tidak ada payment gateway.

## 2. Modul (urutan build, satu branch `modul/<nama>`)
| # | Modul | Fitur | Kesulitan | Bergantung |
| --- | --- | --- | --- | --- |
| 0 | kontrak | F1–F8 (tipe, interface, stub, routes) | sedang | – |
| 1 | fondasi | F1, F10, panduan integrasi | sedang | 0 |
| 2 | auth-lokal | F2, F7 (profil, ganti password), `bin/create-admin.php` | tinggi | 1 |
| 3 | mfa | F3, F7 (MFA), tantangan TOTP login | tinggi | 2 |
| 4 | kunci-jwks | F4 (KeyManager, `bin/rotate-key.php`, `/jwks`, discovery) | sedang | 1 |
| 5 | oauth-flow | F4 (`/authorize`, `/token` 3 grant, `/userinfo`, `/introspect`, `/revoke`, `bin/create-client.php`) | tinggi | 3, 4 |
| 6 | logout | F6, F7 (sesi aktif, keluar semua perangkat), `bin/cron.php` | tinggi | 5 |
| 7 | federasi-inti | F5 (abstrak, registry, IdentityLinker, konfirmasi tautan, lengkapi email), F7 (akun tertaut) | tinggi | 3, 5 |
| 8 | federasi-driver | F5 (9 driver + tes `mapProfile`) | sedang | 7 |
| 9 | admin-inti | F8 (RequireAdmin, RequireRecentAuth, dasbor, audit log + CSV, role, pengaturan, `/install`) | tinggi | 3, 6 |
| 10 | admin-kelola | F8 (klien, provider + upload `.p8`, user & sesi, kunci, pemeliharaan) | tinggi | 4, 7, 9 |
| 11 | pengerasan | F9 (Gc, daftar ancaman §10, `DEPLOY.md`, `CLIENT.md`, conformance) | sedang | semua |
Catatan: 6 dan 7 independen satu sama lain; tetap dijalankan berurutan kecuali orchestrator memutuskan paralel.

## 3. Kontrak antarmuka
Modul 0 membuat SEMUA file di bawah sebagai stub (badan melempar `\LogicException('belum diimplementasi')`), `composer.json`, `phpunit.xml`, `phpstan.neon`, `phpcs.xml`, `bootstrap.php` (container array factory), `routes.php` lengkap dengan controller stub (501). Modul berikutnya mengisi badan, tidak mengubah tanda tangan tanpa revisi architect.

**Bersama / Support** (modul 1)
- `Http\Request` (readonly: method, path, query, body, headers, cookies; `ip(): string`, `input(string,?string=null): ?string`, `bearerToken(): ?string`, `withAttribute(string,mixed): self`, `attribute(string): mixed`). `Http\Response` (`html()`, `json(array,int)`, `redirect(string,int=302)`, `withHeader()`, `withCookie(Cookie)`).
- `Http\Middleware\Middleware::process(Request $r, callable $next): Response`. `Router::add(string $method, string $path, array $handler, array $middleware = [])`, `Router::match(Request): RouteMatch` (404/405). `Kernel::handle(Request): Response`; `Kernel::emit(Response)`.
- `Support\Clock::now(): DateTimeImmutable` (UTC) + `SystemClock`, `FrozenClock` (tes). `Support\Database` (`pdo()`, `transaction(callable)`). `Support\Container` (`set`, `get`, `make(string $class, array $args = [])`).
- `Support\View::render(string $view, array $data = [], string $layout = 'auth', int $status = 200): Response` + helper global `e()`, `asset()`, `csrf_field()` (membaca `Csrf::currentToken()`). Variabel layout: `$title`, `$user`, `$flashes`, `$oneTimeSecret`, admin: `$nav`, `$module`, `$perm`, `$role`.
- `Support\Audit::record(string $event, ?int $actor, ?int $target = null, ?int $clientId = null, array $context = []): void` (menyaring kunci `password|secret|token|code`).
- `Support\HttpClient::request(string $method, string $url, array $headers = [], ?string $body = null): HttpResult`, `postMany(array $requests, int $timeout = 3): array` (TLS verify, timeout 5 s, URL harus lolos `UrlAllowlist`).
- `Support\Mailer::send(string $to, string $subject, string $text): void`. `Support\Markdown::toHtml(string): string` (escape dulu, subset heading/list/code/link http(s)). `Support\Gc::run(): array<string,int>`.
- Exception: `HttpException(int $status)`, `OAuth\OAuthException(string $error, string $description, int $status = 400)`, `Federation\FederationException`.
- Repository (satu per tabel, PDO prepared, `EMULATE_PREPARES=false`): `UserRepository`, `IdentityProviderRepository`, `UserIdentityRepository`, `FederationStateRepository`, `ClientRepository`, `ClientRedirectUriRepository`, `SsoSessionRepository`, `SessionClientRepository`, `AuthCodeRepository`, `RefreshTokenRepository`, `LogoutNotificationRepository`, `SigningKeyRepository`, `AdminRoleRepository`, `SettingRepository`, `LoginAttemptRepository`, `AuditLogRepository`. Mengembalikan DTO readonly di `Repository/Record/` (`User`, `Client`, `SsoSession`, `SigningKey`, …), bukan array. Metode `...ForUpdate()` hanya dipanggil di dalam `transaction()`.

**Security** (modul 2–4)
- `PasswordHasher`: `hash(string): string`, `verify(string $plain, string $hash): bool`, `needsRehash(string): bool` (Argon2id, fallback bcrypt).
- `RateLimiter`: `loginBlocked(string $email, string $ip): bool`, `record(string $identifier, string $ip, bool $success): void`, `tokenAllowed(string $clientId): bool`.
- `Crypto`: `encrypt(string): string` (`<versi>:<base64>`), `decrypt(string): string`, `hmac(string): string`, `reencrypt(string): string`.
- `Totp`: `generateSecret(): string`, `provisioningUri(string $secret, string $label): string`, `verify(string $secret, string $code, ?int $lastStep): ?int` (kembalikan step yang lolos, ±1). `BackupCodes`: `generate(): array{plain: list<string>, hashes: list<string>}`, `consume(User, string): bool`.
- `KeyManager`: `active(): SigningKey`, `jwks(): array`, `sign(array $claims, string $typ): string`, `verify(string $jwt, bool $allowExpired = false): array` (hanya RS256, `kid` wajib dan berstatus next/active/retiring), `rotate(bool $emergency = false): RotationResult` (paksa, hanya `/admin/keys/*`), `rotateIfDue(): ?RotationResult` (jadwal `key.rotation_days`, idempoten, null jika belum jatuh tempo; dipakai `bin/rotate-key.php` & Pemeliharaan).

**Session** (modul 2, 6)
- `SsoSessionManager`: `start(int $userId, string $authMethod, bool $mfaPassed, Request): Cookie`, `current(Request): ?SsoSession`, `markMfaPassed(SsoSession): Cookie` (ganti id), `touch(SsoSession): void`, `clearCookie(): Cookie`.
- `SessionTerminator` (interface): `endSession(string $sid, string $reason): void`, `endAllForUser(int $userId, ?string $exceptSid, string $reason): void`. Modul 2 memakai `LocalSessionTerminator` (hanya DB); modul 6 menggantinya dengan `LogoutService` (+ notifikasi) di container.
- `BackchannelNotifier`: `queue(SsoSession): void`, `dispatchPending(int $limit = 50): int` (curl_multi, backoff 1/5/15/60 menit, berhenti setelah 5).

**OAuth** (modul 5)
- `AuthorizeRequest::fromQuery(array $q, ClientRepository, ClientRedirectUriRepository): self` (melempar `InvalidRedirectException` → halaman error tanpa redirect; `OAuthException` → redirect error).
- `Pkce::verifyS256(string $verifier, string $challenge): bool`. `Scopes::intersect(string $requested, string $allowed): string`.
- `ClientAuthenticator::authenticate(Request, bool $requireConfidential = false): Client` (basic, post, public+PKCE).
- `AuthCodeService`: `issue(AuthorizeRequest, SsoSession): string`, `redeem(string $code, Client, string $redirectUri, string $verifier): AuthCode` (reuse → cabut family via `auth_code_hash`, audit `token.code_reuse`).
- `TokenIssuer`: `idToken(User, Client, SsoSession, string $scope, ?string $nonce): string`, `accessToken(?User, Client, string $scope, ?string $sid): string` (`typ at+jwt`), `logoutToken(Client, string $userUuid, string $sid): string`.
- `RefreshTokenService`: `issue(Client, User, ?string $sessionIdHash, string $scope, ?string $familyId, ?string $authCodeHash): string`, `rotate(string $token, Client): RotatedRefresh` (FOR UPDATE; reuse → cabut family + sesi, audit `token.reuse_detected`), `revoke(string $token, Client): void`.

**Federation** (modul 7–8): persis `ProviderInterface` dan `NormalizedProfile` di requirements §5; `FederationState` (readonly: providerId, nonce, codeVerifier, pendingRequest, linkToUserId); `ProviderRegistry::get(string $slug): ProviderInterface` (daftar putih driver); `IdentityLinker::resolve(NormalizedProfile, IdentityProvider, FederationState): LinkResult` dengan `LinkOutcome` enum `Login|LinkedFromAccount|AutoLinked|NeedsConfirmation|Created|NeedsEmail`.

**Admin** (modul 9–10): `AdminPolicy::MATRIX` (konstanta publik `array<role, array<modul, 'none'|'read'|'manage'>>`, kunci modul: dashboard, clients, providers, users, keys, audit, maintenance, settings, roles; `audit` manage = ekspor CSV) dan `AdminPolicy::level(string $role, string $module): 'none'|'read'|'manage'` (role/modul tak dikenal → `none`), dipakai nav & guard. `InstallGuard::open(): bool` (`admin_roles` kosong DAN tidak ada `storage/install.lock`).

**Rute & middleware** (modul 0, `src/sso/routes.php`; revisi T0)
- Entri middleware: `class-string<Middleware>` atau `[class-string<Middleware>, ...argumen rute]`. Kernel me-resolve lewat `Container::make(string $class, array $args = [])`: factory menyuntikkan dependensi layanan lebih dulu, argumen rute terakhir (mis. `RequireAdmin(AdminPolicy, AdminRoleRepository, string $module, string $need)`). Urutan eksekusi = urutan array.
- Global (Kernel): `SecurityHeaders`, `Csrf`. `Csrf` mengecualikan POST `/token`, `/introspect`, `/revoke`, `/userinfo` (client auth/bearer) dan POST `/federation/{slug}/callback` (lihat bawah).
- `RequireSsoSession`: `SsoSessionManager::current()`; tidak ada → GET: redirect `/login?return=<path relatif>`, POST: 303 ke `/login`. Lolos → atribut `sso_session`, `user`; memanggil `touch()`.
- `RateLimit` (hanya `/token`): `RateLimiter::tokenAllowed(client_id)` (client_id dari Basic atau body, tanpa autentikasi); gagal → 429 JSON `{"error":"temporarily_unavailable"}` + `Retry-After: 60` + `no-store`.
- `RequireAdmin(module, need)`: role dari `admin_roles`, user tanpa MFA → `/account/mfa`; level < need → 403; idle 15 menit dari `last_seen_at` → paksa login ulang. Atribut `admin_role`.
- `RequireRecentAuth` (selalu SETELAH `RequireAdmin`): `mfa_passed_at` > 5 menit → simpan aksi tertunda (method, path, body tanpa `_csrf`, kedaluwarsa 5 menit, sekali pakai, hanya POST `/admin/*`) di sesi UI, redirect `/admin/stepup`. `StepUpController::submit` memverifikasi TOTP (limit `mfa:<id>`), `markMfaPassed()`, lalu menjalankan aksi tertunda lewat `Kernel::handle()` dengan token CSRF baru.
- Wajib `RequireRecentAuth` (§8 + F8): klien store/update/regenerate-secret; provider update; SEMUA rute kunci termasuk `/admin/maintenance/rotate-keys`; settings update; roles grant/revoke; users `status`, `reset-mfa`, `force-reset`, `unlink/{slug}`. Tanpa step-up (aksi defensif/baca): toggle klien/provider, test-logout, test-connection, cabut sesi user, gc, retry-logout, ekspor audit.
- `/admin/maintenance/rotate-keys` → `MaintenanceController::rotateKeys` memanggil `rotateIfDue()` saja (bukan rotasi paksa), guard `maintenance:manage` + `RequireRecentAuth`; operator boleh (§8 "Jalankan"), audit `key.rotate_scheduled`.
- Publik akun: `RegisterController` `form|submit` (`/register`), `verify` (`GET /verify-email?token=`; token HMAC 24 jam, idempoten); `PasswordResetController` `forgotForm|forgotSubmit` (`/password/forgot`, respons generik selalu sama) dan `resetForm|resetSubmit` (`/password/reset?token=`; sukses → `SessionTerminator::endAllForUser`). Limit `login_attempts`: `register:<ip>`, `forgot:<email>` (5/15 menit). `StepUpController` `form|submit` (`/admin/stepup`, `RequireSsoSession`).
- Federasi: GET `/federation/{slug}/callback` → `callback` (satu-satunya pemroses). POST (Apple `form_post`, cookie Lax TIDAK terkirim) → `formPostCallback`: bebas CSRF middleware, hanya slug yang driver-nya `form_post` (lainnya 405); cari state via hash tanpa mengonsumsi, simpan `user` Apple ke `pending_request` (`FederationStateRepository::mergePending`), lalu 303 ke GET callback dengan `state`, `code`/`error` saja. GET `callback` mengonsumsi state (hapus), mencocokkan hash state dengan yang disimpan di sesi UI saat `start` (pengikat browser = perlindungan CSRF/login-CSRF), lalu nonce & PKCE.

**Repository** (lihat tanda tangan di `src/sso/src/Repository/`): pola `findBy*()` → `?Record`, `findBy*ForUpdate()` (dalam `transaction()`), `list*()` → `list<Record>`, plus `create`/`update*`/`delete`/`revoke*` yang ditambah modul pengisi tanpa mengubah metode yang ada. Metode baru T0: `FederationStateRepository::mergePending(string $stateHash, array $extra): bool` (false jika tidak ada/kedaluwarsa).

## 4. Threat model ringkas
Aset: password hash, secret TOTP & kode cadangan, cookie sesi, authorization code, refresh/access/ID token, private key RS256 (`keys/`), secret klien & provider, file `.p8`, `secrets.php`, data pribadi (email, nama, `raw_profile`, IP), audit log.
Aktor: user akhir, admin (3 role), aplikasi klien first-party, provider eksternal, penyerang anonim di internet, sesama penghuni shared hosting.
| Modul | Ancaman utama | Mitigasi |
| --- | --- | --- |
| fondasi | XSS, clickjacking, CSRF, SQLi, file konfigurasi terekspos | `e()` di semua view, CSP tanpa inline script, `frame-ancestors 'none'`, token CSRF per sesi UI, prepared statement, hanya `public_html` di webroot (`Require all denied` bila terpaksa) |
| auth-lokal | brute force, enumerasi email, session fixation/hijack | rate limit §10, pesan generik, `__Host-` Secure/HttpOnly/Lax, ganti id setelah login & MFA, idle/absolute timeout |
| mfa | brute force/replay TOTP, kebocoran secret | limit `mfa:<id>`, `mfa_last_step`, sodium secretbox berkeyring, kode cadangan HMAC sekali pakai |
| kunci-jwks | kebocoran private key, `alg:none`/HS256, kunci retired | file 600 di luar webroot, tulis atomik, verifikasi pin RS256 + `kid` dikenal, rotasi darurat |
| oauth-flow | code/refresh reuse, open redirect, PKCE downgrade, race | exact match URI, S256 wajib, hash di DB, `hash_equals`, transaksi FOR UPDATE, pencabutan family, `no-store` |
| logout | open redirect `/logout`, SSRF via `backchannel_logout_uri`, logout token dipalsukan | exact match `post_logout`, HttpClient allowlist + https, logout token RS256 tanpa `nonce` |
| federasi | state/nonce CSRF, account takeover via email tak terverifikasi, injeksi driver, SSRF discovery | state hash + kedaluwarsa 10 menit, nonce & PKCE, cabang 3 hanya jika `trust_email` + verified, daftar putih driver, URL hanya dari konfigurasi provider, MFA tetap diminta |
| admin | eskalasi hak, aksi tanpa step-up, kebocoran secret di HTML, admin menghapus diri | `AdminPolicy` + tes matriks, `RequireRecentAuth`, secret write-only & sekali tampil, audit before/after, superadmin terakhir dilindungi, `/install` terkunci |
| semua | rahasia di log/commit | `Audit`/logger menyaring, `config/secrets.php`, `keys/`, `storage/` di `.gitignore` |
Pembayaran: tidak ada; jika kelak ditambah, wajib tokenisasi gateway dan data kartu tidak menyentuh server.

## 5. Keputusan terbuka (pemilik proyek)
- QR code MFA: tidak ada library QR yang disetujui. Usulan: setujui `chillerlan/php-qrcode` (MIT, SVG); sementara tampilkan kunci manual + `otpauth://` tanpa QR.
- `requirements.md` jangan dihapus sampai modul 11 selesai (dokumen ini merujuknya; batas 120 baris).
- SMTP vs `mail()`, PHP-FPM, cron, SSH, domain final: asumsi requirements §12 berlaku.
