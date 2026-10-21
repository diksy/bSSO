# Panduan Integrasi BikininSSO

Dokumen untuk tim yang menghubungkan aplikasi ke BikininSSO. Semua nilai contoh hanyalah placeholder. Ganti `https://sso.contoh.test` dengan base URL SSO yang sebenarnya.

## 1. Ringkasan dan istilah

BikininSSO adalah OIDC Provider. Aplikasi Anda (klien/RP) tidak pernah menerima token dari Google atau provider lain, hanya dari SSO. Satu-satunya alur login user adalah Authorization Code + PKCE (S256). Tidak ada halaman consent.

| Istilah | Arti |
| --- | --- |
| RP / klien | Aplikasi Anda yang memakai SSO |
| Confidential client | Klien dengan secret yang aman di server |
| Public client | SPA atau aplikasi mobile, tanpa secret, wajib PKCE |
| `sid` | ID sesi SSO; dipakai untuk logout |
| `kid` | ID kunci penandatangan di header JWT |
| JWKS | Kumpulan kunci publik untuk verifikasi JWT |

| Item | Nilai contoh |
| --- | --- |
| Base URL | `https://sso.contoh.test` |
| Discovery URL | `https://sso.contoh.test/.well-known/openid-configuration` |
| JWKS URL | `https://sso.contoh.test/jwks` |
| Scope didukung | `openid`, `profile`, `email`, `offline_access` |

## 2. Pilih jenis klien

| Jenis | Cocok untuk | Grant | Secret | PKCE |
| --- | --- | --- | --- | --- |
| Confidential server-side | Aplikasi web dengan backend | `authorization_code`, `refresh_token` | Ya | Wajib |
| Public (SPA/mobile) | Browser/aplikasi mobile tanpa backend | `authorization_code`, `refresh_token` | Tidak | Wajib |
| Server-ke-server | Layanan tanpa user | `client_credentials` | Ya | Tidak berlaku |

Minta hal berikut ke admin SSO (sertakan alasan dan daftar URL):

| Item | Keterangan |
| --- | --- |
| `client_id` | Diberikan admin |
| `client_secret` | Hanya confidential; ditampilkan sekali, simpan di secret manager |
| Redirect URI jenis `login` | Cocok persis (tanpa wildcard); contoh `https://app.contoh.test/callback` |
| Redirect URI jenis `post_logout` | Tujuan setelah logout; contoh `https://app.contoh.test/signed-out` |
| `backchannel_logout_uri` | Endpoint POST di aplikasi Anda; contoh `https://app.contoh.test/backchannel-logout` |
| Scope | Pilih dari scope didukung; scope API custom didaftarkan per klien |

## 3. Tabel endpoint

| Method | Path | Fungsi | Auth |
| --- | --- | --- | --- |
| GET | `/.well-known/openid-configuration` | Discovery document | Publik |
| GET | `/jwks` | Kunci publik, `Cache-Control: max-age=3600` | Publik |
| GET | `/authorize` | Memulai login, menerbitkan code | Sesi SSO atau form login |
| POST | `/token` | Grant `authorization_code`, `refresh_token`, `client_credentials` | Client auth |
| GET, POST | `/userinfo` | Profil user sesuai scope | Bearer access token |
| POST | `/introspect` | Status aktif token (RFC 7662) | Client auth (confidential) |
| POST | `/revoke` | Cabut refresh token (RFC 7009) | Client auth |
| GET | `/logout` | RP-initiated logout | `id_token_hint` |

Client auth di `/token`: `client_secret_basic` (utama, header `Authorization: Basic base64(client_id:client_secret)`) atau `client_secret_post` (field `client_id` dan `client_secret` di body). Public client tidak mengirim secret.

## 4. Alur Authorization Code + PKCE

### 4.1 Buat code_verifier, code_challenge, state, nonce

`code_verifier` 43 sampai 128 karakter acak. `code_challenge` = base64url(SHA-256(`code_verifier`)). Simpan `code_verifier`, `state`, `nonce` di sesi lokal.

```php
<?php
function b64url(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }

$verifier  = b64url(random_bytes(32));          // 43 karakter
$challenge = b64url(hash('sha256', $verifier, true));
$state     = b64url(random_bytes(16));
$nonce     = b64url(random_bytes(16));
$_SESSION['oidc'] = compact('verifier', 'state', 'nonce');
```

### 4.2 Redirect user ke /authorize

| Parameter | Wajib | Nilai |
| --- | --- | --- |
| `response_type` | Ya | `code` |
| `client_id` | Ya | `client_id` Anda |
| `redirect_uri` | Ya | Persis sama dengan yang terdaftar |
| `scope` | Ya | Contoh `openid profile email offline_access` |
| `state` | Ya | Nilai acak; dikembalikan apa adanya |
| `nonce` | Ya | Nilai acak; muncul di ID token |
| `code_challenge` | Ya | Hasil langkah 4.1 |
| `code_challenge_method` | Ya | `S256` (hanya ini yang diterima) |

```http
GET /authorize?response_type=code&client_id=app-contoh&redirect_uri=https%3A%2F%2Fapp.contoh.test%2Fcallback&scope=openid%20profile%20email%20offline_access&state=Xk3p9&nonce=Qm7aZ&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256 HTTP/1.1
Host: sso.contoh.test
```

Jika `redirect_uri` tidak valid, SSO menampilkan halaman error dan tidak melakukan redirect.

### 4.3 Terima callback

Setelah login, SSO redirect ke `redirect_uri?code=...&state=...`. Periksa `state` sama dengan yang disimpan; jika beda, tolak. Code berlaku 60 detik dan sekali pakai.

```http
HTTP/1.1 302 Found
Location: https://app.contoh.test/callback?code=SplxlOBeZQQYbYS6WxSbIA&state=Xk3p9
```

### 4.4 Tukar code di /token

```bash
curl -s -X POST https://sso.contoh.test/token \
  -u 'app-contoh:SECRET_DUMMY_GANTI_SENDIRI' \
  -d grant_type=authorization_code \
  -d code=SplxlOBeZQQYbYS6WxSbIA \
  -d redirect_uri=https://app.contoh.test/callback \
  -d code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
```

Public client: hilangkan `-u`, tambahkan `-d client_id=app-contoh`.

```json
{
  "token_type": "Bearer",
  "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6ImF0K2p3dCIsImtpZCI6ImRlbW8ta2lkIn0...",
  "expires_in": 900,
  "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6ImRlbW8ta2lkIn0...",
  "refresh_token": "dummyRefreshTokenAaBbCc123",
  "scope": "openid profile email offline_access"
}
```

`refresh_token` hanya ada jika scope memuat `offline_access`. Validasi ID token (bagian 7), lalu cocokkan `nonce`.

```php
<?php
$o = $_SESSION['oidc'];
if (!hash_equals($o['state'], $_GET['state'] ?? '')) { http_response_code(400); exit; }

$ch = curl_init('https://sso.contoh.test/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_USERPWD => 'app-contoh:SECRET_DUMMY_GANTI_SENDIRI',
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => 'https://app.contoh.test/callback',
        'code_verifier' => $o['verifier'],
    ]),
]);
$tokens = json_decode(curl_exec($ch), true);
```

Code yang dipakai dua kali menyebabkan semua refresh token dari code itu dicabut.

## 5. Refresh token, client credentials, userinfo, introspect, revoke

### 5.1 Refresh token (rotasi)

Setiap pemakaian menghasilkan refresh token baru; yang lama langsung tidak valid. Simpan yang baru. Memakai ulang token lama menghasilkan `invalid_grant`, seluruh keluarga token dan sesi SSO dicabut; user harus login ulang.

```bash
curl -s -X POST https://sso.contoh.test/token \
  -u 'app-contoh:SECRET_DUMMY_GANTI_SENDIRI' \
  -d grant_type=refresh_token \
  -d refresh_token=dummyRefreshTokenAaBbCc123
```

```json
{
  "token_type": "Bearer",
  "access_token": "eyJhbGciOiJSUzI1NiIs...",
  "expires_in": 900,
  "refresh_token": "dummyRefreshTokenDdEeFf456"
}
```

Refresh token juga tidak valid jika sesi dicabut, user di-suspend, atau klien dinonaktifkan. Umur default 30 hari.

### 5.2 Client credentials

Hanya untuk confidential client yang diberi grant `client_credentials`. Hasil: access token dengan `sub` = `client_id`; tanpa ID token, tanpa refresh token.

```bash
curl -s -X POST https://sso.contoh.test/token \
  -u 'layanan-contoh:SECRET_DUMMY_GANTI_SENDIRI' \
  -d grant_type=client_credentials \
  -d scope=api.baca
```

```php
<?php
$ch = curl_init('https://sso.contoh.test/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
    CURLOPT_USERPWD => 'layanan-contoh:SECRET_DUMMY_GANTI_SENDIRI',
    CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials', 'scope' => 'api.baca']),
]);
$token = json_decode(curl_exec($ch), true)['access_token'];
```

### 5.3 Userinfo

```bash
curl -s https://sso.contoh.test/userinfo -H 'Authorization: Bearer ACCESS_TOKEN_DUMMY'
```

```json
{ "sub": "6f1c2a9e-0000-4000-8000-000000000001", "name": "Budi Contoh", "email": "budi@contoh.test" }
```

Isi sesuai scope. Respons memakai `Cache-Control: no-store`.

### 5.4 Introspect

Gunakan jika API butuh pencabutan instan. Access token tidak bisa dicabut sebelum kedaluwarsa; introspect mengecek sesi (`sid`) dan status user. Hanya confidential client.

```bash
curl -s -X POST https://sso.contoh.test/introspect \
  -u 'app-contoh:SECRET_DUMMY_GANTI_SENDIRI' \
  -d token=ACCESS_TOKEN_DUMMY
```

```json
{ "active": true, "scope": "openid profile", "client_id": "app-contoh", "sub": "6f1c2a9e-0000-4000-8000-000000000001", "exp": 1790000000 }
```

Token tidak aktif: `{"active": false}`.

### 5.5 Revoke

```bash
curl -s -X POST https://sso.contoh.test/revoke \
  -u 'app-contoh:SECRET_DUMMY_GANTI_SENDIRI' \
  -d token=dummyRefreshTokenDdEeFf456
```

Mencabut refresh token (RFC 7009). Public client mengirim `client_id` tanpa secret.

## 6. Isi token

Semua JWT bertanda tangan RS256 dan memuat `kid` di header.

### 6.1 ID token (umur 15 menit)

| Claim | Arti |
| --- | --- |
| `iss` | Base URL SSO; harus cocok persis |
| `sub` | UUID user, stabil; pakai sebagai kunci akun |
| `aud` | `client_id` Anda; harus cocok |
| `exp`, `iat` | Waktu kedaluwarsa dan terbit |
| `auth_time` | Waktu user login |
| `nonce` | Harus sama dengan yang Anda kirim |
| `sid` | ID sesi SSO; simpan untuk logout |
| `amr` | Metode autentikasi, contoh `["pwd","otp"]` |
| `email`, `name` | Ada bila scope `email`/`profile` diminta |

### 6.2 Access token (header `typ: at+jwt`, umur default 900 detik)

| Claim | Arti |
| --- | --- |
| `iss`, `sub`, `aud`, `exp`, `iat` | Seperti ID token; `sub` = `client_id` untuk client credentials |
| `jti` | ID unik token |
| `scope` | Scope yang diberikan, dipisah spasi |
| `client_id` | Klien yang meminta token |
| `sid` | ID sesi SSO (tidak ada pada client credentials) |

### 6.3 Logout token (header `typ: logout+jwt`, umur 2 menit)

| Claim | Arti |
| --- | --- |
| `iss`, `aud`, `iat`, `exp`, `jti` | `aud` = `client_id` Anda |
| `sub`, `sid` | User dan sesi yang berakhir |
| `events` | `{"http://schemas.openid.net/event/backchannel-logout": {}}` |
| `nonce` | Tidak boleh ada |

## 7. Verifikasi JWT lewat JWKS

1. Ambil `https://sso.contoh.test/jwks`, cache sesuai `Cache-Control` (1 jam).
2. Baca `kid` dan `alg` dari header JWT. Tolak jika `alg` bukan `RS256` (tolak `none` dan `HS256`).
3. Pilih kunci dengan `kid` yang sama. Jika `kid` tidak dikenal, ambil ulang JWKS, maksimal satu kali per menit; jika tetap tidak ada, tolak.
4. Verifikasi tanda tangan, lalu `iss`, `aud`, `exp` (toleransi jam maks. 60 detik), dan untuk ID token juga `nonce`.
5. Untuk access token di API: periksa `typ` = `at+jwt`, `scope`, dan `aud`.

```php
<?php
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

JWT::$leeway = 60;
$jwks = json_decode(file_get_contents('/var/cache/app/jwks.json'), true); // hasil GET /jwks
$keys = JWK::parseKeySet($jwks, 'RS256');   // memaksa RS256, HS256/none ditolak

$claims = JWT::decode($idToken, $keys);     // melempar exception jika kid/tanda tangan/exp salah
if ($claims->iss !== 'https://sso.contoh.test') { throw new RuntimeException('iss'); }
if ($claims->aud !== 'app-contoh' && !in_array('app-contoh', (array) $claims->aud, true)) { throw new RuntimeException('aud'); }
if (!hash_equals($_SESSION['oidc']['nonce'], $claims->nonce ?? '')) { throw new RuntimeException('nonce'); }
```

Instal: `composer require firebase/php-jwt`. Kunci berotasi tiap 90 hari dengan masa tumpang tindih; jangan menanam kunci di kode.

## 8. Logout

### 8.1 RP-initiated logout

1. Hapus sesi lokal aplikasi.
2. Redirect browser ke `/logout`.

| Parameter | Wajib | Nilai |
| --- | --- | --- |
| `id_token_hint` | Ya | ID token user (yang kedaluwarsa tetap diterima) |
| `post_logout_redirect_uri` | Tidak | Harus cocok persis dengan URI `post_logout` terdaftar; jika tidak cocok SSO menampilkan halaman "Anda sudah keluar" tanpa redirect |
| `state` | Tidak | Dikembalikan ke `post_logout_redirect_uri` |

```http
GET /logout?id_token_hint=eyJhbGciOi...&post_logout_redirect_uri=https%3A%2F%2Fapp.contoh.test%2Fsigned-out&state=Zt82 HTTP/1.1
Host: sso.contoh.test
```

### 8.2 Kewajiban back-channel logout

Saat sesi SSO berakhir (logout dari aplikasi lain, ganti password, suspend admin, dan lain-lain), SSO mengirim `POST` ke `backchannel_logout_uri` Anda dengan `Content-Type: application/x-www-form-urlencoded` dan body `logout_token=<jwt>`. Aplikasi WAJIB:

1. Memverifikasi tanda tangan lewat JWKS (bagian 7), serta `iss`, `aud` = `client_id` Anda, dan `events` memuat kunci `http://schemas.openid.net/event/backchannel-logout`.
2. Memastikan tidak ada claim `nonce`.
3. Memutus semua sesi lokal dengan `sid` yang sama (tabel pemetaan `sid` ke sesi lokal, atau daftar `sid` tercabut yang dicek di setiap request).
4. Membalas `200` atau `204`. Selain itu dianggap gagal.

SSO mencoba ulang dengan jeda 1, 5, 15, 60 menit, berhenti setelah 5 kali gagal. Timeout per percobaan 3 detik, jadi proses cepat.

```php
<?php
use Firebase\JWT\JWT;

$claims = JWT::decode($_POST['logout_token'] ?? '', $keys); // $keys dari bagian 7
$event  = 'http://schemas.openid.net/event/backchannel-logout';
if ($claims->iss !== 'https://sso.contoh.test'
    || !in_array('app-contoh', (array) $claims->aud, true)
    || !isset($claims->events->$event)
    || isset($claims->nonce)) {
    http_response_code(400); exit;
}
revoke_local_sessions_by_sid($claims->sid);   // fungsi milik aplikasi Anda
http_response_code(204);
```

## 9. Kode error dan batas laju

### 9.1 Kode error OAuth

| Kode | Terjadi saat | Penanganan |
| --- | --- | --- |
| `invalid_request` | Parameter hilang atau salah format | Perbaiki request; jangan retry otomatis |
| `invalid_client` | Client auth salah, klien nonaktif | Periksa `client_id`/secret; hubungi admin |
| `invalid_grant` | Code kedaluwarsa/terpakai, `code_verifier` salah, `redirect_uri` beda, refresh token terpakai ulang atau sesi dicabut | Mulai login baru dari `/authorize`; jangan retry |
| `unauthorized_client` | Klien tidak punya grant tersebut | Minta admin menambah grant |
| `unsupported_grant_type` | `grant_type` tidak dikenal | Pakai grant yang didukung |
| `invalid_scope` | Scope tidak diizinkan untuk klien | Kurangi scope atau minta admin |
| `access_denied` | User menolak/akses ditolak | Tampilkan pesan, jangan retry |
| `invalid_token` (userinfo) | Access token kedaluwarsa/tidak valid | Refresh token atau login ulang |
| `temporarily_unavailable` | Layanan sibuk | Retry dengan backoff |

Error pada `/authorize` dengan `redirect_uri` tidak valid ditampilkan di halaman SSO, bukan lewat redirect. Respons error `/token` berbentuk `{"error": "invalid_grant", "error_description": "..."}` dengan status 400 (401 untuk `invalid_client`).

### 9.2 Rate limit

| Target | Batas |
| --- | --- |
| Login | 5 gagal per email per 15 menit; 20 gagal per IP per 15 menit |
| `/token` | 60 request per klien per menit |

Melebihi batas: status `429`. Tunggu sebelum mencoba lagi.

## 10. Checklist go-live

- [ ] `client_id`, secret, redirect URI `login` dan `post_logout`, serta `backchannel_logout_uri` sudah terdaftar di admin SSO
- [ ] Semua URL memakai HTTPS; `redirect_uri` cocok persis, tanpa wildcard
- [ ] PKCE S256 dipakai di setiap login; `state` dan `nonce` dibuat acak dan diperiksa
- [ ] Secret disimpan di secret manager/env server, tidak di kode, repo, atau aplikasi mobile/SPA
- [ ] ID token diverifikasi: tanda tangan lewat JWKS, `iss`, `aud`, `exp`, `nonce`; `alg` selain RS256 ditolak
- [ ] JWKS di-cache, pilih kunci by `kid`, refetch maksimal sekali per menit saat `kid` tidak dikenal
- [ ] Akun lokal dikaitkan dengan claim `sub`, bukan email
- [ ] Refresh token yang baru disimpan setelah setiap rotasi; `invalid_grant` mengarahkan user ke login ulang
- [ ] API memeriksa `typ` = `at+jwt`, `aud`, `scope`; memakai `/introspect` bila perlu pencabutan instan
- [ ] Logout: sesi lokal dihapus lalu redirect ke `/logout` dengan `id_token_hint`
- [ ] Endpoint back-channel logout memverifikasi logout token, memutus sesi by `sid`, membalas 200/204 dengan cepat
- [ ] Token, code, dan secret tidak masuk log
- [ ] Penanganan 429 dan `temporarily_unavailable` dengan backoff
- [ ] Uji end-to-end di staging: login, refresh, reuse refresh token, logout, back-channel logout
