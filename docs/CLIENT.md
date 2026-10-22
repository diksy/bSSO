<!-- maks. 120 baris · pemilik: orchestrator (F9) -->
# Panduan Klien BikininSSO (ringkas)

Panduan lengkap beserta contoh request/response berada di halaman `https://<sso>/panduan-integrasi` (HTML) dan `/panduan-integrasi.md` (Markdown mentah untuk AI agent). Sumbernya: `src/sso/content/panduan-integrasi.md`. Dokumen ini hanya ringkasan kontrak; contoh kode tidak diduplikasi di sini.

## Mendaftarkan klien

- Admin: `/admin/clients` (secret ditampilkan SEKALI), atau CLI `php bin/create-client.php` (opsi di komentar berkas).
- Jenis: `confidential` (server-side, punya secret) atau `public` (SPA/mobile, wajib PKCE). `client_credentials` hanya untuk confidential dan tidak memerlukan redirect URI.
- Redirect URI login dan post-logout dicocokkan PERSIS (tanpa wildcard/prefix); https wajib (http hanya localhost).

## Kontrak ringkas

| Hal | Ketentuan |
| --- | --- |
| Alur | Authorization Code + PKCE `S256` untuk semua klien; tidak ada halaman consent |
| Discovery | `/.well-known/openid-configuration`; kunci publik di `/jwks` (cache, hormati `kid`) |
| Token | ID token dan access token RS256 (`typ` access token `at+jwt`); refresh token opaque, dirotasi tiap pemakaian |
| Verifikasi | Cek tanda tangan lewat JWKS, `iss`, `aud`, `exp`, dan `nonce`; tolak `alg` selain RS256 |
| Refresh | Pemakaian ulang refresh token lama mencabut seluruh family: simpan token terbaru secara atomik |
| Pencabutan | Access token tidak bisa dicabut sebelum `exp`; API sensitif memanggil `/introspect` (cek sesi `sid` dan user) |
| Batas laju | `/token` 60 request/menit per pasangan klien+IP (dan batas per IP); `/introspect` dan `/revoke` 120/menit. Balasan 429 membawa `Retry-After` |

## Logout

1. RP-initiated: hapus sesi lokal lalu arahkan ke `/logout?id_token_hint&post_logout_redirect_uri&state` (URI harus terdaftar persis).
2. Back-channel: daftarkan `backchannel_logout_uri` (https, port 443, host publik). Verifikasi logout token lewat JWKS (`iss`, `aud`, `events`, tanpa `nonce`), balas `200`/`204`, putus sesi lokal dengan `sid` yang sama. Dikirim ulang dengan backoff sampai 5 kali.

## Checklist go-live klien

- [ ] `state`, `nonce`, dan `code_verifier` acak per permintaan (`random_bytes`), diverifikasi saat callback
- [ ] secret klien hanya di server (env/secret manager), tidak di repo atau log
- [ ] verifikasi JWT penuh; cache JWKS dengan refresh saat `kid` tak dikenal
- [ ] endpoint back-channel logout diuji (lihat tombol uji di admin klien)
- [ ] penanganan 429 dengan backoff; tidak ada loop retry ketat di `/token`
