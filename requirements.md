# Spesifikasi Platform SSO PHP

Oct 20, 2026 · @Diksy M. Firmansyah

## 1. Ringkasan & cara memakai dokumen

Platform ini adalah Identity Provider berbasis **OAuth 2.0 + OpenID Connect (OIDC)** yang ditulis dalam PHP tanpa framework, berjalan di shared hosting dengan MariaDB, dan mendukung login lokal maupun login lewat provider eksternal (Google, Microsoft, Apple, GitHub, GitLab, Facebook, LinkedIn, Discord, X).

**Untuk agen AI yang membaca dokumen ini:**

- Dokumen ini adalah sumber kebenaran. Jika instruksi tugas bertentangan dengan dokumen ini, berhenti dan tanyakan ke pemilik proyek.
- Kata **HARUS** dan **JANGAN** adalah aturan wajib. Kata **SEBAIKNYA** adalah rekomendasi yang boleh dilanggar dengan alasan tertulis di pull request.
- Kerjakan per milestone (bagian 11). Setiap milestone punya kriteria penerimaan yang harus lulus sebelum milestone berikutnya dimulai.
- Nama tabel, kolom, endpoint, dan class di dokumen ini adalah nama final. Jangan mengganti nama tanpa memperbarui dokumen.
- Jika sesuatu tidak tercantum di sini, pilih solusi paling sederhana yang sesuai standar OIDC, lalu catat di bagian 12.

**Lingkup**

| Termasuk | Tidak termasuk |
| --- | --- |
| Login lokal (email + password) dan MFA TOTP | Multi-tenant (satu organisasi saja) |
| Login via provider eksternal + penautan akun | Klien pihak ketiga dan halaman consent |
| Authorization Code + PKCE, Refresh Token, Client Credentials | Implicit flow, Password grant |
| Single logout via back-channel | Front-channel logout |
| Halaman admin | SAML |

**Batasan teknis (wajib)**

| Aspek | Ketentuan |
| --- | --- |
| Bahasa | PHP 8.2+ dengan `declare(strict_types=1);` di setiap file |
| Framework | Tidak ada. Routing, controller, dan container ditulis sendiri |
| Library yang diizinkan | `firebase/php-jwt` (JWT/JWKS). Library lain harus disetujui pemilik proyek |
| Ekstensi PHP | `pdo_mysql`, `openssl`, `sodium`, `curl`, `mbstring`, `json` |
| Database | MariaDB 10.5+, InnoDB, utf8mb4 |
| Hosting | Shared hosting (cPanel). Tidak ada Redis, queue, atau proses long-running |
| Penjadwalan | Cron cPanel jika tersedia; jika tidak, garbage collection probabilistik |
| Klien | Semua aplikasi klien milik sendiri (first-party) |

## 2. Arsitektur & endpoint

SSO berperan ganda: sebagai **OIDC Provider** bagi aplikasi klien, dan sebagai **broker** ke provider eksternal. Aplikasi klien hanya pernah menerima token dari SSO, tidak pernah dari Google atau provider lain.

**Dua mode pemakaian**

- **Direct (browser):** aplikasi server-side mengarahkan user ke `/authorize`, menerima `code`, lalu menukarnya di `/token`. Sesi SSO berupa cookie di domain SSO, sehingga login sekali berlaku untuk semua aplikasi.
- **API:** SPA dan mobile memakai Authorization Code + PKCE sebagai public client. Komunikasi antar-server memakai Client Credentials. API memverifikasi access token JWT lewat JWKS tanpa memanggil SSO.

**Endpoint**

| Method | Path | Fungsi | Auth |
| --- | --- | --- | --- |
| GET | `/.well-known/openid-configuration` | Discovery document | Publik |
| GET | `/jwks` | Kunci publik (status `next`, `active`, `retiring`), header `Cache-Control: max-age=3600` | Publik |
| GET | `/authorize` | Memulai login, menerbitkan authorization code | Sesi SSO atau form login |
| POST | `/token` | Grant `authorization_code`, `refresh_token`, `client_credentials` | Client auth |
| GET, POST | `/userinfo` | Profil user sesuai scope | Bearer access token |
| POST | `/introspect` | Status aktif sebuah token (RFC 7662) | Client auth (confidential) |
| POST | `/revoke` | Mencabut refresh token (RFC 7009) | Client auth |
| GET | `/logout` | RP-initiated logout | `id_token_hint` |
| GET, POST | `/login`, `/login/mfa` | Form login lokal dan verifikasi TOTP | CSRF |
| GET | `/federation/{slug}/start` | Redirect ke provider eksternal | Publik |
| GET, POST | `/federation/{slug}/callback` | Callback provider (POST untuk Apple) | `state` |
| GET, POST | `/account/*` | Profil, ganti password, MFA, akun tertaut, sesi aktif | Sesi SSO |
| GET, POST | `/admin/*` | Halaman admin (bagian 8) | Sesi SSO + role admin |

**Scope yang didukung:** `openid`, `profile`, `email`, `offline_access` (wajib untuk menerima refresh token). Scope API custom didaftarkan per klien di `clients.allowed_scopes`.

**Client authentication di `/token`:** `client_secret_basic` (utama) dan `client_secret_post`. Public client tidak punya secret dan HARUS memakai PKCE.

## 3. Alur token

Satu-satunya alur login user adalah **Authorization Code + PKCE (S256)**, untuk semua jenis klien. Tidak ada halaman consent karena semua klien first-party.

**Alur Authorization Code**

1. Klien membuat `code_verifier` acak (43–128 karakter), lalu redirect ke `/authorize?response_type=code&client_id&redirect_uri&scope&state&nonce&code_challenge&code_challenge_method=S256`.
2. SSO memvalidasi: klien aktif, `redirect_uri` cocok **persis** dengan salah satu baris `client_redirect_uris` jenis `login`, scope diizinkan, `code_challenge_method` = `S256`. Jika `redirect_uri` tidak valid, tampilkan halaman error dan JANGAN redirect.
3. SSO mengecek cookie sesi. Jika tidak ada sesi valid, tampilkan form login atau tombol provider. Parameter `/authorize` asli disimpan sementara selama user login.
4. SSO menerbitkan authorization code (32 byte acak, base64url), menyimpan hash-nya di `auth_codes` dengan masa berlaku 60 detik, mencatat baris `session_clients`, lalu redirect ke `redirect_uri?code&state`.
5. Klien memanggil `POST /token` dengan `grant_type=authorization_code`, `code`, `redirect_uri`, `code_verifier`, dan client auth jika confidential.
6. SSO memverifikasi code (belum dipakai, belum kedaluwarsa, `client_id` dan `redirect_uri` sama), memverifikasi PKCE, mengisi `used_at`, lalu membalas token.
7. Jika code yang sudah dipakai dikirim lagi, SSO HARUS mencabut semua refresh token yang terbit dari code tersebut dan mencatat `token.code_reuse` di `audit_logs`.

**Spesifikasi token**

| Token | Format | Umur default | Disimpan di DB | Claim / isi |
| --- | --- | --- | --- | --- |
| Authorization code | Acak 32 byte | 60 detik | Hash SHA-256 | – |
| ID token | JWT RS256 | 15 menit | Tidak | `iss`, `sub` (= `users.uuid`), `aud`, `exp`, `iat`, `auth_time`, `nonce`, `sid`, `amr`, plus `email`/`name` sesuai scope |
| Access token | JWT RS256 | `clients.access_token_ttl` (900 detik) | Tidak | `iss`, `sub`, `aud`, `exp`, `iat`, `jti`, `scope`, `client_id`, `sid` |
| Refresh token | Acak 32 byte (opaque) | `clients.refresh_token_ttl` (30 hari) | Hash SHA-256 | – |

Semua JWT HARUS mencantumkan `kid` di header. Header `typ` access token = `at+jwt` (RFC 9068).

**Rotasi refresh token**

1. Klien memanggil `POST /token` dengan `grant_type=refresh_token`.
2. Jika token valid dan `used_at` kosong: isi `used_at`, terbitkan refresh token baru dengan `family_id` yang sama, serta access token baru.
3. Jika token sudah punya `used_at` (dipakai ulang): cabut semua token dalam `family_id` itu, cabut sesi SSO terkait, catat `token.reuse_detected`, lalu balas `invalid_grant`.
4. Refresh token juga tidak valid jika sesi SSO terkait sudah dicabut, user di-suspend, atau klien dinonaktifkan.
5. Langkah 2 dan 3 HARUS berjalan dalam satu transaksi dengan `SELECT ... FOR UPDATE` untuk mencegah race condition.

**Client Credentials**

Hanya untuk klien confidential yang punya grant `client_credentials`. Hasilnya access token dengan `sub` = `client_id`, tanpa ID token dan tanpa refresh token.

**Pencabutan access token**

Access token tidak bisa dicabut sebelum kedaluwarsa. API yang butuh pencabutan instan HARUS memanggil `/introspect`, yang mengecek status sesi (`sid`) dan user di DB.

## 4. Skema database

Skema terdiri dari 16 tabel InnoDB utf8mb4. Aturan umum:

- Token, code, dan ID sesi JANGAN pernah disimpan mentah. Kolom `*_hash` berisi SHA-256 hex dari nilai asli.
- Secret yang harus bisa dibaca ulang (secret provider, secret TOTP) disimpan di kolom `*_enc`, dienkripsi dengan sodium (bagian 7).
- Client secret disimpan dengan `password_hash()` karena cukup diverifikasi, tidak perlu dibaca ulang.
- Semua waktu disimpan dalam UTC.
- Migrasi disimpan sebagai file bernomor di `database/migrations/` (misalnya `001_init.sql`) dan dijalankan berurutan oleh `bin/migrate.php`.

```sql
SET NAMES utf8mb4;

-- ===== USER & IDENTITAS =====
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,                       -- claim "sub"
  email VARCHAR(255) NULL,
  email_verified_at DATETIME NULL,
  password_hash VARCHAR(255) NULL,              -- NULL = hanya login via provider
  name VARCHAR(150) NULL,
  avatar_url VARCHAR(500) NULL,
  mfa_secret_enc VARBINARY(255) NULL,
  mfa_enabled_at DATETIME NULL,
  status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_uuid (uuid),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE identity_providers (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(30) NOT NULL,
  display_name VARCHAR(60) NOT NULL,
  protocol ENUM('oidc','oauth2') NOT NULL,
  driver VARCHAR(30) NOT NULL,                  -- nama class di Federation/Drivers
  client_id VARCHAR(255) NOT NULL,
  client_secret_enc VARBINARY(1024) NULL,
  discovery_url VARCHAR(500) NULL,
  authorize_url VARCHAR(500) NULL,
  token_url VARCHAR(500) NULL,
  userinfo_url VARCHAR(500) NULL,
  scopes VARCHAR(255) NOT NULL,
  trust_email TINYINT(1) NOT NULL DEFAULT 0,
  extra_config JSON NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_idp_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider_id SMALLINT UNSIGNED NOT NULL,
  provider_user_id VARCHAR(255) NOT NULL,       -- id dari provider, BUKAN email
  email VARCHAR(255) NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  raw_profile JSON NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_identity (provider_id, provider_user_id),
  KEY idx_identity_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (provider_id) REFERENCES identity_providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE federation_states (
  state_hash CHAR(64) PRIMARY KEY,
  provider_id SMALLINT UNSIGNED NOT NULL,
  nonce VARCHAR(128) NULL,
  code_verifier VARCHAR(128) NULL,
  pending_request JSON NOT NULL,                -- parameter /authorize asli
  link_to_user_id BIGINT UNSIGNED NULL,         -- diisi saat menautkan dari halaman akun
  expires_at DATETIME NOT NULL,                 -- +10 menit
  KEY idx_fs_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== KLIEN =====
CREATE TABLE clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id VARCHAR(80) NOT NULL,
  client_secret_hash VARCHAR(255) NULL,         -- NULL = public client
  name VARCHAR(100) NOT NULL,
  type ENUM('confidential','public') NOT NULL,
  allowed_grants SET('authorization_code','refresh_token','client_credentials') NOT NULL,
  allowed_scopes VARCHAR(255) NOT NULL DEFAULT 'openid profile email',
  access_token_ttl INT UNSIGNED NOT NULL DEFAULT 900,
  refresh_token_ttl INT UNSIGNED NOT NULL DEFAULT 2592000,
  backchannel_logout_uri VARCHAR(500) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_redirect_uris (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  uri VARCHAR(500) NOT NULL,
  kind ENUM('login','post_logout') NOT NULL DEFAULT 'login',
  KEY idx_cru_client (client_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== SESI & TOKEN =====
CREATE TABLE sso_sessions (
  id_hash CHAR(64) PRIMARY KEY,                 -- hash nilai cookie
  sid CHAR(36) NOT NULL,                        -- claim "sid", publik
  user_id BIGINT UNSIGNED NOT NULL,
  auth_method VARCHAR(30) NOT NULL,             -- password, google, github, ...
  mfa_passed_at DATETIME NULL,                  -- dipakai juga untuk step-up admin
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_sid (sid),
  KEY idx_sess_user (user_id),
  KEY idx_sess_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_clients (
  session_id_hash CHAR(64) NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  first_login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id_hash, client_id),
  FOREIGN KEY (session_id_hash) REFERENCES sso_sessions(id_hash) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_codes (
  code_hash CHAR(64) PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id_hash CHAR(64) NOT NULL,
  redirect_uri VARCHAR(500) NOT NULL,
  scope VARCHAR(255) NOT NULL,
  nonce VARCHAR(128) NULL,
  code_challenge VARCHAR(128) NOT NULL,
  code_challenge_method ENUM('S256') NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  KEY idx_code_expires (expires_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE refresh_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  family_id CHAR(36) NOT NULL,
  auth_code_hash CHAR(64) NULL,                 -- asal family, untuk pencabutan saat code reuse
  client_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id_hash CHAR(64) NULL,
  scope VARCHAR(255) NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  KEY idx_rt_family (family_id),
  KEY idx_rt_code (auth_code_hash),
  KEY idx_rt_user (user_id),
  KEY idx_rt_expires (expires_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE logout_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  sid CHAR(36) NOT NULL,
  user_uuid CHAR(36) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at DATETIME NOT NULL,
  delivered_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  KEY idx_ln_pending (delivered_at, next_try_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signing_keys (
  kid VARCHAR(64) PRIMARY KEY,                  -- JWK thumbprint (RFC 7638)
  alg ENUM('RS256') NOT NULL DEFAULT 'RS256',
  public_key_pem TEXT NOT NULL,
  private_key_path VARCHAR(255) NOT NULL,       -- file di luar public_html
  status ENUM('next','active','retiring','retired') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  retiring_at DATETIME NULL,
  retired_at DATETIME NULL,
  KEY idx_sk_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== ADMIN, KEAMANAN, AUDIT =====
CREATE TABLE admin_roles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  role ENUM('superadmin','operator','auditor') NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  name VARCHAR(64) PRIMARY KEY,
  value JSON NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(255) NOT NULL,
  ip_address VARBINARY(16) NOT NULL,
  success TINYINT(1) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_ident (identifier, attempted_at),
  KEY idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,                 -- pelaku (user atau admin)
  target_user_id BIGINT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  event VARCHAR(50) NOT NULL,
  ip_address VARBINARY(16) NULL,
  context JSON NULL,                            -- termasuk nilai before/after untuk aksi admin
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_event (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Data awal provider** (`database/seeds/providers.sql`). Semua provider dibuat dalam keadaan nonaktif (`is_enabled = 0`). Admin mengisi `client_id` dan secret lewat halaman admin, lalu mengaktifkannya.

| slug | protocol | discovery / authorize URL | scopes | trust\_email |
| --- | --- | --- | --- | --- |
| google | oidc | `https://accounts.google.com/.well-known/openid-configuration` | `openid email profile` | 1 |
| microsoft | oidc | `https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration` | `openid email profile` | 0 |
| apple | oidc | `https://appleid.apple.com/.well-known/openid-configuration` | `name email` | 1 |
| github | oauth2 | `https://github.com/login/oauth/authorize` | `read:user user:email` | 0 |
| gitlab | oidc | `https://gitlab.com/.well-known/openid-configuration` | `openid email profile` | 0 |
| facebook | oauth2 | `https://www.facebook.com/dialog/oauth` | `email public_profile` | 0 |
| linkedin | oidc | `https://www.linkedin.com/oauth/.well-known/openid-configuration` | `openid email profile` | 0 |
| discord | oauth2 | `https://discord.com/oauth2/authorize` | `identify email` | 0 |
| x | oauth2 | `https://x.com/i/oauth2/authorize` | `users.read tweet.read` | 0 |

URL token dan userinfo untuk provider `oauth2`: GitHub `https://github.com/login/oauth/access_token` dan `https://api.github.com/user`; Facebook `https://graph.facebook.com/oauth/access_token` dan `https://graph.facebook.com/me?fields=id,name,email,picture`; Discord `https://discord.com/api/oauth2/token` dan `https://discord.com/api/users/@me`; X `https://api.x.com/2/oauth2/token` dan `https://api.x.com/2/users/me`. Agen HARUS memverifikasi ulang semua URL di dokumentasi resmi provider sebelum implementasi.

## 5. Federasi provider eksternal

Menambah provider baru cukup dengan satu baris di `identity_providers` dan satu class driver kecil. Logika OIDC/OAuth2 umum ada di class abstrak.

**Kontrak driver**

```php
interface ProviderInterface
{
    public function authorizationUrl(string $state, ?string $nonce, ?string $pkceChallenge): string;

    /** @throws FederationException */
    public function handleCallback(array $params, FederationState $state): NormalizedProfile;
}

final class NormalizedProfile
{
    public function __construct(
        public readonly string $providerUserId,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $avatarUrl,
        public readonly array $raw,
    ) {}
}
```

**Class abstrak**

- `AbstractOidcProvider`: membaca discovery (cache 24 jam di `storage/cache`), menukar code dengan PKCE, memvalidasi `id_token` (signature lewat JWKS provider, `iss`, `aud`, `exp`, `nonce`). Jika `kid` tidak dikenal, ambil ulang JWKS satu kali. Subclass cukup meng-override `mapProfile(array $claims): NormalizedProfile`.
- `AbstractOAuth2Provider`: menukar code, lalu memanggil `userinfo_url` dengan access token. Subclass meng-override `mapProfile(array $userinfo)`.
- `ProviderRegistry`: membaca baris `identity_providers` yang aktif dan membuat instance dari kolom `driver`. Nama class divalidasi terhadap daftar putih, JANGAN di-instansiasi langsung dari string DB.

**Catatan per provider**

| Provider | Basis | Perlakuan khusus |
| --- | --- | --- |
| Google | OIDC | Cek claim `email_verified` |
| Microsoft | OIDC | Endpoint `common` mengembalikan `iss` berisi tenant ID, jadi validasi `iss` memakai pola `https://login.microsoftonline.com/{tid}/v2.0`. `provider_user_id` = gabungan `tid` + `oid`. Email tidak dijamin terverifikasi |
| Apple | OIDC | Client secret berupa JWT ES256 yang dibuat dari file `.p8` (`team_id`, `key_id` di `extra_config`). Memakai `response_mode=form_post`, jadi callback berupa POST. Nama user hanya dikirim di login pertama, simpan saat itu |
| GitHub | OAuth2 | Panggil `GET /user/emails` lalu ambil email dengan `primary` dan `verified` = true. `provider_user_id` = `id` numerik |
| GitLab | OIDC | Standar |
| Facebook | OAuth2 | Email bisa kosong. Gunakan Graph API versi yang ditetapkan di `extra_config` |
| LinkedIn | OIDC | Standar (Sign In with LinkedIn using OpenID Connect) |
| Discord | OAuth2 | Gunakan field `verified` untuk status email |
| X | OAuth2 + PKCE | Tidak menyediakan email. Profil ditandai "butuh email" |

**Keputusan setelah callback (`IdentityLinker`)**

1. Jika `(provider_id, provider_user_id)` sudah ada di `user_identities`: login sebagai user tersebut.
2. Jika `link_to_user_id` terisi (user menautkan dari halaman akun): tautkan ke user itu, lalu kembali ke halaman akun.
3. Jika email terverifikasi, provider punya `trust_email = 1`, dan email cocok dengan user yang ada: tautkan otomatis dan login.
4. Jika email cocok dengan user yang ada tetapi syarat langkah 3 tidak terpenuhi: tampilkan halaman konfirmasi yang meminta user login dengan metode lamanya dulu, baru tautkan.
5. Jika email tidak cocok dengan siapa pun: buat user baru. Jika email kosong (misalnya X), minta user mengisi email dan verifikasi lewat tautan email sebelum akun aktif.

User yang sudah mengaktifkan MFA HARUS tetap melewati verifikasi TOTP setelah login lewat provider mana pun.

URL callback untuk didaftarkan di console provider: `https://<domain-sso>/federation/{slug}/callback`.

## 6. Logout & single logout

Logout mencabut sesi SSO, semua refresh token milik sesi itu, lalu memberi tahu setiap aplikasi lain dalam sesi lewat **back-channel logout** (OIDC Back-Channel Logout 1.0). Front-channel tidak dipakai karena bergantung pada cookie pihak ketiga.

**Alur RP-initiated logout**

1. Aplikasi menghapus sesi lokalnya, lalu redirect ke `/logout?id_token_hint&post_logout_redirect_uri&state`.
2. SSO memverifikasi `id_token_hint` (signature valid, token kedaluwarsa tetap diterima) untuk mendapatkan `sid` dan `client_id`.
3. `post_logout_redirect_uri` HARUS cocok persis dengan baris `client_redirect_uris` jenis `post_logout` milik klien tersebut. Jika tidak cocok, tampilkan halaman "Anda sudah keluar" tanpa redirect.
4. Panggil `LogoutService::endSession($sid, $reason)`.
5. Redirect ke `post_logout_redirect_uri?state`.

**`LogoutService::endSession` (dipakai oleh semua pemicu logout)**

1. Dalam satu transaksi: isi `sso_sessions.revoked_at`, isi `refresh_tokens.revoked_at` untuk semua token dengan `session_id_hash` yang sama, dan buat baris `logout_notifications` untuk setiap klien di `session_clients` yang punya `backchannel_logout_uri`.
2. Hapus cookie sesi jika request berasal dari browser user tersebut.
3. Kirim notifikasi (lihat di bawah) dan catat `session.ended` di `audit_logs`.

**Pemicu logout lain**

| Pemicu | Cakupan |
| --- | --- |
| Ganti password, reset MFA | Semua sesi user, kecuali sesi yang sedang dipakai |
| Refresh token dipakai ulang | Sesi terkait |
| Admin men-suspend user | Semua sesi user |
| "Keluar dari semua perangkat" di halaman akun | Semua sesi user |
| Admin mencabut sesi | Sesi yang dipilih |

**Logout token**

JWT RS256 dengan header `typ: logout+jwt` dan claim `iss`, `aud` (= `client_id`), `iat`, `exp` (+2 menit), `jti`, `sub`, `sid`, serta `events: {"http://schemas.openid.net/event/backchannel-logout": {}}`. Logout token JANGAN berisi `nonce`. Dikirim sebagai `POST` dengan body `logout_token=<jwt>` (`application/x-www-form-urlencoded`).

**Pengiriman di shared hosting**

1. Jika tersedia PHP-FPM, redirect user dulu, panggil `fastcgi_finish_request()`, lalu kirim.
2. Kirim ke semua klien secara paralel dengan `curl_multi`, timeout 3 detik per klien.
3. Respons `200` atau `204`: isi `delivered_at`. Gagal: tambah `attempts`, isi `last_error`, jadwalkan ulang dengan backoff 1, 5, 15, 60 menit. Setelah 5 kali gagal, hentikan dan tampilkan di dasbor admin.
4. Retry dijalankan oleh `bin/cron.php` atau oleh GC probabilistik.

**Kewajiban aplikasi klien** (didokumentasikan untuk tim yang membangun aplikasi klien)

- Memverifikasi logout token lewat JWKS, termasuk `iss`, `aud`, `events`, dan tidak adanya `nonce`, lalu membalas `200`.
- Memutus sesi lokal yang `sid`-nya sama, entah lewat tabel pemetaan `sid → session_id` atau daftar `sid` yang dicabut yang dicek di setiap request.

## 7. Rotasi kunci

Signing key berotasi otomatis setiap 90 hari dengan masa tumpang tindih 2 hari di kedua sisi. Kunci publik HARUS sudah ada di `/jwks` sebelum dipakai, dan tetap ada sampai token terakhir yang ditandatanganinya kedaluwarsa.

**Siklus hidup signing key**

| Status | Ada di `/jwks` | Dipakai signing | Durasi |
| --- | --- | --- | --- |
| `next` | Ya | Tidak | Minimal 2 hari |
| `active` | Ya | Ya (tepat satu kunci) | 90 hari |
| `retiring` | Ya | Tidak | Minimal 2 hari |
| `retired` | Tidak | Tidak | File private key sudah dihapus |

Durasi diatur lewat `settings` (`key.rotation_days`, `key.overlap_days`).

**`bin/rotate-key.php` (cron harian, idempoten)**

1. Jika belum ada kunci `next` dan kunci `active` berumur ≥ `rotation_days − overlap_days`: buat kunci baru berstatus `next`.
2. Jika kunci `next` berumur ≥ `overlap_days` dan kunci `active` berumur ≥ `rotation_days`: dalam satu transaksi, ubah `active` → `retiring` dan `next` → `active`.
3. Jika kunci `retiring` sudah berstatus itu selama ≥ `overlap_days`: ubah menjadi `retired` dan hapus file private key-nya.
4. Jika tidak ada kunci `active` sama sekali (instalasi baru): buat kunci langsung berstatus `active`.

**Ketentuan teknis**

- Kunci RSA 2048 bit dibuat dengan `openssl_pkey_new`.
- `kid` = JWK thumbprint SHA-256 (RFC 7638), base64url.
- File private key ditulis secara atomik: tulis ke file sementara, `chmod 600`, lalu `rename` ke `keys/<kid>.pem`.
- `KeyManager` meng-cache kunci aktif per request, tidak membaca file berulang kali.

**Rotasi darurat:** `php bin/rotate-key.php --emergency`

1. Buat kunci baru langsung berstatus `active`.
2. Ubah kunci yang bocor langsung menjadi `retired` dan hapus file-nya.
3. Catat `key.emergency_rotation` di `audit_logs`.

Semua access token aktif akan ditolak, tetapi klien cukup memakai refresh token (opaque, tidak terpengaruh) untuk mendapatkan token baru.

**Aturan untuk verifikator** (aplikasi klien dan SSO sendiri saat memverifikasi token provider): pilih kunci berdasarkan `kid`. Jika `kid` tidak ada di cache, ambil ulang JWKS maksimal sekali per menit.

**Kunci enkripsi sodium**

- Dipakai untuk `identity_providers.client_secret_enc` dan `users.mfa_secret_enc` (`sodium_crypto_secretbox`).
- Keyring disimpan di `config/secrets.php`: `['current' => 'v2', 'keys' => ['v1' => '...', 'v2' => '...']]`.
- Format ciphertext: `<versi>:<nonce+ciphertext dalam base64>`. Dekripsi memakai versi sesuai prefiks, enkripsi selalu memakai versi `current`.
- `bin/reencrypt.php` mengenkripsi ulang data lama ke versi terbaru. Kunci lama baru dihapus setelah skrip ini selesai.

## 8. Halaman admin

Halaman admin berada di `/admin`, dibangun dengan HTML yang di-render server, form biasa, dan sedikit CSS, tanpa SPA. Admin login lewat SSO itu sendiri dan wajib memakai MFA.

**Hak akses per role**

| Modul | superadmin | operator | auditor |
| --- | --- | --- | --- |
| Dasbor | Baca | Baca | Baca |
| Aplikasi klien | Kelola | – | Baca |
| Provider | Kelola | – | Baca |
| User & sesi | Kelola | Kelola (kecuali role admin) | Baca |
| Kunci | Kelola | – | Baca |
| Audit log | Baca + ekspor | Baca | Baca + ekspor |
| Pemeliharaan | Jalankan | Jalankan | – |
| Pengaturan | Kelola | – | Baca |
| Role admin | Kelola | – | – |

**Aturan keamanan (wajib)**

- Middleware `RequireAdmin` mengecek `admin_roles`. User tanpa MFA aktif ditolak dan diarahkan untuk mengaktifkan MFA.
- Middleware `RequireRecentAuth` meminta TOTP ulang jika `sso_sessions.mfa_passed_at` lebih dari 5 menit lalu. Middleware ini wajib untuk: ubah redirect URI atau grant klien, regenerasi secret, ubah credential provider, semua aksi kunci, ubah role admin, dan ubah pengaturan.
- Idle timeout sesi admin 15 menit (`settings.admin.idle_minutes`).
- Setiap form memakai token CSRF. Aksi yang mengubah data HARUS memakai POST.
- Secret bersifat write-only. Form secret selalu kosong, dan nilai lama tidak pernah dikirim ke browser. Client secret baru ditampilkan sekali saja setelah dibuat.
- Setiap aksi yang mengubah data dicatat di `audit_logs` dengan `context` berisi nilai `before` dan `after` (tanpa secret).
- Admin tidak bisa mengubah atau mencabut role-nya sendiri. Superadmin terakhir tidak bisa dihapus.
- Fitur impersonasi user TIDAK dibuat.

**Modul**

1. **Dasbor:** jumlah login berhasil dan gagal (24 jam), sesi aktif, kejadian `token.reuse_detected` terbaru, `logout_notifications` yang gagal permanen, dan sisa hari sampai rotasi kunci berikutnya.
2. **Aplikasi klien:** daftar, tambah, edit, nonaktifkan. Field: nama, tipe, grant, scope, TTL, redirect URI (`login` dan `post_logout`), `backchannel_logout_uri`. Tombol "regenerasi secret" dan "kirim logout token uji".
3. **Provider:** aktif/nonaktif, urutan tombol, `client_id`, secret, `extra_config`, upload file `.p8` untuk Apple (disimpan di `keys/`, bukan DB). Tampilkan URL callback siap salin. Tombol "tes koneksi" mengambil discovery dan JWKS provider.
4. **User:** cari berdasarkan email atau nama. Detail: identitas tertaut, sesi aktif, riwayat login. Aksi: suspend/aktifkan, putuskan tautan provider, reset MFA, paksa reset password (kirim email), cabut sesi.
5. **Kunci:** daftar kunci beserta status dan tanggalnya, tombol rotasi manual, dan tombol rotasi darurat yang mewajibkan admin mengetik kata `ROTATE`.
6. **Audit log:** filter berdasarkan pelaku, target, event, dan rentang tanggal, dengan paginasi dan ekspor CSV.
7. **Pemeliharaan:** tombol untuk menjalankan GC, retry notifikasi logout, dan rotasi kunci terjadwal. Disediakan untuk hosting tanpa cron.
8. **Pengaturan:** umur sesi SSO, kebijakan password (panjang minimum), ambang rate limit, opsi MFA wajib untuk semua user, `key.rotation_days`, `key.overlap_days`.
9. **Role admin:** beri, ubah, atau cabut role. Hanya untuk superadmin.

**Pembuatan superadmin pertama:** `php bin/create-admin.php --email=...`. Jika SSH tidak tersedia, sediakan halaman `/install` yang hanya aktif selama tabel `admin_roles` masih kosong dan file `storage/install.lock` belum ada.

## 9. Struktur folder & konvensi kode

Hanya `public_html/index.php` yang bisa diakses dari web. Semua kode, konfigurasi, dan kunci berada di luar webroot.

```text
/home/<akun>/
├── sso/
│   ├── bootstrap.php            autoload, config, error handler, container
│   ├── composer.json            namespace App\ → src/
│   ├── config/                  app.php, database.php, secrets.php (tidak di-commit)
│   ├── keys/                    <kid>.pem, apple-*.p8 (chmod 600, tidak di-commit)
│   ├── database/
│   │   ├── migrations/          001_init.sql, 002_..., dst.
│   │   └── seeds/               providers.sql
│   ├── src/
│   │   ├── Http/                Router, Request, Response, Kernel
│   │   │   └── Middleware/      Csrf, RateLimit, SecurityHeaders,
│   │   │                        RequireSsoSession, RequireAdmin, RequireRecentAuth
│   │   ├── Controller/          Authorize, Token, UserInfo, Jwks, Discovery,
│   │   │   │                    Introspect, Revoke, Login, Mfa, Federation,
│   │   │   │                    Logout, Install
│   │   │   ├── Account/         Profile, Password, MfaSetup, LinkedAccounts, Sessions
│   │   │   └── Admin/           Dashboard, Clients, Providers, Users, Keys,
│   │   │                        AuditLog, Maintenance, Settings, Roles
│   │   ├── OAuth/               AuthorizeRequest, AuthCodeService, TokenIssuer,
│   │   │                        RefreshTokenService, Pkce, ClientAuthenticator, Scopes
│   │   ├── Federation/          ProviderInterface, AbstractOidcProvider,
│   │   │   │                    AbstractOAuth2Provider, ProviderRegistry,
│   │   │   │                    IdentityLinker, NormalizedProfile, FederationState
│   │   │   └── Drivers/         GoogleDriver, MicrosoftDriver, AppleDriver,
│   │   │                        GitHubDriver, GitLabDriver, FacebookDriver,
│   │   │                        LinkedInDriver, DiscordDriver, XDriver
│   │   ├── Session/             SsoSessionManager, LogoutService, BackchannelNotifier
│   │   ├── Security/            PasswordHasher, Totp, Crypto, KeyManager, RateLimiter
│   │   ├── Repository/          satu class per tabel (PDO, prepared statement)
│   │   └── Support/             Database, Container, HttpClient, Clock, Gc, Audit, View
│   ├── views/                   layout.php, login.php, mfa.php, error.php,
│   │                            account/*, admin/*
│   ├── bin/                     migrate.php, cron.php, rotate-key.php, reencrypt.php,
│   │                            create-client.php, create-admin.php
│   ├── storage/                 logs/, cache/ (tidak di-commit)
│   └── tests/                   Unit/, Integration/
└── public_html/
    ├── index.php                require __DIR__ . '/../sso/bootstrap.php';
    ├── .htaccess                rewrite semua request ke index.php
    └── assets/                  css, gambar
```

Jika hosting tidak mengizinkan file di luar `public_html`, letakkan `sso/` di dalamnya dengan `.htaccess` berisi `Require all denied`.

**Alur request:** `index.php` → `bootstrap.php` → `Kernel` → middleware global (`SecurityHeaders`, `Csrf`) → `Router` → middleware rute → controller → service → repository.

**Konvensi kode (wajib)**

- PSR-4 dan PSR-12. Setiap file diawali `declare(strict_types=1);`. Class bersifat `final` kecuali memang untuk diwarisi.
- Controller tipis: membaca `Request`, memanggil service, mengembalikan `Response`. Logika bisnis tidak boleh ada di controller atau view.
- SQL hanya ada di `Repository/`, selalu dengan prepared statement. PDO memakai `ERRMODE_EXCEPTION` dan `EMULATE_PREPARES = false`.
- Container berupa array factory sederhana di `bootstrap.php`. Tidak ada library DI.
- Waktu selalu diambil dari `Clock` supaya bisa di-mock dalam test.
- Semua output di view di-escape dengan helper `e()` (`htmlspecialchars` + `ENT_QUOTES`).
- Respons error di endpoint OAuth mengikuti format standar (`error`, `error_description`) dan tidak membocorkan detail internal.
- Error ditulis ke `storage/logs/app-YYYY-MM-DD.log`. `display_errors` mati di produksi.
- Test memakai PHPUnit (dev dependency) dengan database MariaDB terpisah untuk test integrasi.

## 10. Persyaratan keamanan

Semua butir di bawah wajib dan menjadi bagian dari kriteria penerimaan di setiap milestone.

| Area | Ketentuan |
| --- | --- |
| Password | `password_hash` dengan `PASSWORD_ARGON2ID` (fallback `PASSWORD_BCRYPT` jika Argon2 tidak tersedia). Panjang minimum 10 karakter. Rehash otomatis saat login jika `password_needs_rehash` |
| Bilangan acak | Hanya `random_bytes`. JANGAN pakai `rand`, `mt_rand`, atau `uniqid` |
| Perbandingan rahasia | `hash_equals` untuk token, code, dan hash. `password_verify` untuk password dan client secret |
| Cookie sesi SSO | `Secure`, `HttpOnly`, `SameSite=Lax`, prefiks `__Host-`, nilai 32 byte acak. ID sesi diganti setelah login dan setelah MFA |
| CSRF | Token per sesi di setiap form POST. Endpoint `/token`, `/introspect`, `/revoke` dikecualikan karena memakai client auth |
| Redirect URI | Exact match. Tidak ada wildcard, prefix match, atau normalisasi longgar |
| PKCE | Wajib untuk semua klien. Hanya `S256` |
| `state` dan `nonce` | Wajib ke provider eksternal. `state` dari klien diteruskan apa adanya |
| Rate limit | Login: 5 gagal per email per 15 menit, 20 gagal per IP per 15 menit. `/token`: 60 request per klien per menit. Pesan error login tidak membedakan "email tidak ada" dan "password salah" |
| MFA | TOTP (RFC 6238, 30 detik, toleransi ±1 langkah). Kode yang sudah dipakai tidak bisa dipakai lagi dalam jendela yang sama. Sediakan 10 kode cadangan sekali pakai (simpan hash-nya) |
| HTTP header | `Strict-Transport-Security`, `Content-Security-Policy` ketat (tanpa inline script), `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `frame-ancestors 'none'` |
| Cache | `Cache-Control: no-store` untuk respons `/token`, `/userinfo`, dan halaman login |
| HTTPS | Wajib. Request HTTP di-redirect ke HTTPS |
| HTTP keluar | `HttpClient` memakai verifikasi TLS, timeout 5 detik, dan hanya ke URL dari konfigurasi provider atau `backchannel_logout_uri` |
| Logging | JANGAN mencatat password, token, code, secret, atau kode TOTP di log mana pun |
| Garbage collection | Hapus data kedaluwarsa dari `auth_codes`, `federation_states`, `refresh_tokens` (+7 hari), `sso_sessions` (+7 hari), `login_attempts` (+30 hari). `audit_logs` disimpan 1 tahun |

**Daftar ancaman yang harus diuji**

- [ ] Authorization code dipakai dua kali
- [ ] Refresh token dipakai ulang setelah rotasi
- [ ] `redirect_uri` dimodifikasi (path, query, subdomain, port)
- [ ] PKCE `code_verifier` salah atau tidak dikirim
- [ ] `state` atau `nonce` dari provider eksternal tidak cocok
- [ ] Penautan akun lewat email yang belum terverifikasi
- [ ] Brute force login dan TOTP
- [ ] CSRF pada form login, akun, dan admin
- [ ] Akses admin tanpa role atau tanpa step-up
- [ ] Token yang ditandatangani kunci `retired` atau `kid` tidak dikenal
- [ ] JWT dengan `alg: none` atau `alg: HS256`
- [ ] Open redirect di `/logout`

## 11. Rencana kerja per milestone

Pekerjaan dibagi menjadi 8 milestone. M1–M4 berurutan. Setelah M4 selesai, M5, M6, dan M7 boleh dikerjakan paralel oleh agen berbeda. M8 dikerjakan terakhir.

**M1 — Fondasi**

- Isi: `bootstrap.php`, `Kernel`, `Router`, `Request`/`Response`, container, `Database`, `Clock`, `View`, error handler, logging, `SecurityHeaders`, `Csrf`, `bin/migrate.php`, migrasi `001_init.sql`, seed provider.
- Kriteria penerimaan:
  - [ ] `php bin/migrate.php` membuat ke-16 tabel di MariaDB kosong tanpa error
  - [ ] Rute yang tidak ada mengembalikan 404 dengan halaman error
  - [ ] Semua header keamanan bagian 10 muncul di respons
  - [ ] PHPUnit berjalan dan minimal satu test untuk `Router` lulus

**M2 — User lokal & sesi SSO**

- Isi: `PasswordHasher`, `RateLimiter`, `SsoSessionManager`, `LoginController`, form login, halaman akun dasar (profil, ganti password), `bin/create-admin.php`.
- Kriteria penerimaan:
  - [ ] Login dengan password benar membuat baris `sso_sessions` dan cookie `__Host-` sesuai ketentuan
  - [ ] Login gagal ke-6 untuk email yang sama dalam 15 menit ditolak
  - [ ] Ganti password mengakhiri semua sesi lain milik user

**M3 — MFA TOTP**

- Isi: `Totp`, `Crypto` (keyring sodium), `MfaController`, setup MFA dengan QR code, kode cadangan.
- Kriteria penerimaan:
  - [ ] Kode TOTP lulus uji vektor RFC 6238
  - [ ] Kode yang sama tidak bisa dipakai dua kali
  - [ ] `mfa_secret_enc` di DB tidak bisa dibaca tanpa kunci di `secrets.php`

**M4 — Inti OIDC**

- Isi: `KeyManager`, `bin/rotate-key.php`, `/jwks`, discovery, `/authorize`, `/token` (semua grant), `/userinfo`, `/introspect`, `/revoke`, `bin/create-client.php`.
- Kriteria penerimaan:
  - [ ] Alur lengkap berhasil dengan library klien OIDC pihak ketiga (misalnya `jumbojett/openid-connect-php`) sebagai aplikasi uji
  - [ ] Semua ancaman terkait token dan redirect di bagian 10 diuji dan ditolak
  - [ ] Rotasi refresh token dan deteksi pemakaian ulang lulus test integrasi
  - [ ] Simulasi rotasi kunci (dengan `Clock` yang di-mock) melewati keempat status tanpa token valid yang ditolak

**M5 — Federasi**

- Isi: class abstrak, `ProviderRegistry`, `IdentityLinker`, `FederationController`, kesembilan driver, halaman konfirmasi penautan, halaman "lengkapi email", menu akun tertaut.
- Kriteria penerimaan:
  - [ ] Login Google, Microsoft, dan GitHub berhasil end-to-end di lingkungan staging
  - [ ] Kelima cabang `IdentityLinker` punya test unit
  - [ ] Validasi `id_token` menolak `iss`, `aud`, `nonce`, dan signature yang salah
  - [ ] Driver lain punya test unit `mapProfile` dengan contoh respons dari dokumentasi provider

**M6 — Logout**

- Isi: `LogoutService`, `BackchannelNotifier`, `/logout`, menu "keluar dari semua perangkat", retry di `bin/cron.php`.
- Kriteria penerimaan:
  - [ ] Logout di aplikasi uji A memicu logout token ke aplikasi uji B dalam satu request
  - [ ] Klien yang mati dicoba ulang sesuai jadwal backoff dan berhenti setelah 5 kali
  - [ ] `post_logout_redirect_uri` yang tidak terdaftar tidak menyebabkan redirect

**M7 — Halaman admin**

- Isi: `RequireAdmin`, `RequireRecentAuth`, kesembilan modul admin, halaman `/install`.
- Kriteria penerimaan:
  - [ ] Matriks hak akses bagian 8 diuji untuk setiap role
  - [ ] Aksi yang butuh step-up meminta TOTP setelah 5 menit
  - [ ] Tidak ada secret yang muncul di HTML mana pun (cek dengan pencarian string di respons)
  - [ ] Setiap aksi admin menghasilkan baris `audit_logs` dengan `before`/`after`

**M8 — Pengerasan & rilis**

- Isi: GC, penyelesaian daftar ancaman di bagian 10, panduan deploy ke shared hosting (`docs/DEPLOY.md`), panduan integrasi untuk aplikasi klien (`docs/CLIENT.md`).
- Kriteria penerimaan:
  - [ ] Semua butir daftar ancaman tercentang
  - [ ] Instalasi bersih di shared hosting nyata berhasil mengikuti `docs/DEPLOY.md` saja
  - [ ] Hasil pemeriksaan [OpenID Connect Conformance Suite](https://www.certification.openid.net/) untuk profil Basic OP dicatat, dan setiap kegagalan diperbaiki atau dijelaskan

## 12. Keputusan terbuka & asumsi

Asumsi di bawah dipakai sampai pemilik proyek memutuskan lain. Agen yang menemukan keputusan baru HARUS menambahkannya ke tabel ini.

| Topik | Asumsi saat ini | Perlu dikonfirmasi |
| --- | --- | --- |
| Versi PHP hosting | 8.2 atau lebih baru | Versi pasti di hosting produksi |
| PHP-FPM | Tersedia (`fastcgi_finish_request`) | Jika tidak, notifikasi logout dikirim sinkron |
| Cron | Tersedia di cPanel | Jika tidak, andalkan GC probabilistik dan menu Pemeliharaan |
| SSH | Tersedia untuk menjalankan `bin/` | Jika tidak, pakai `/install` dan menu admin |
| Pengiriman email | Fungsi `mail()` hosting atau SMTP | Penyedia SMTP dan alamat pengirim |
| Domain | SSO di subdomain sendiri, misalnya `sso.<domain>` | Nama domain final |
| Umur sesi SSO | 8 jam idle, maksimal 30 hari dengan "ingat saya" | Nilai final |
| MFA | Opsional untuk user biasa, wajib untuk admin | Apakah wajib untuk semua user |
| Pendaftaran user | Terbuka untuk siapa saja | Apakah dibatasi undangan atau domain email tertentu |
| Provider aktif saat rilis | Google, Microsoft, Apple, GitHub | Daftar final |
| Bahasa antarmuka | Bahasa Indonesia | Apakah perlu bahasa Inggris |
| Tampilan | HTML + CSS sederhana tanpa framework CSS | Identitas visual (logo, warna) |
