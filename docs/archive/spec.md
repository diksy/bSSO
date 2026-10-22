<!-- maks ~1 halaman (60 baris). Pemilik: orchestrator. Dibaca: semua agen (reviewer: fitur terkait saja) -->
# Spesifikasi Produk – BikininSSO

## Tujuan
- Identity Provider OAuth 2.0 + OIDC untuk satu organisasi: login sekali berlaku di semua aplikasi first-party, dengan login lokal maupun lewat 9 provider eksternal.
- Pengguna: user akhir aplikasi klien, admin/operator/auditor SSO, tim pengembang aplikasi klien.
- Detail teknis (skema 16 tabel, endpoint, alur token, kontrak driver) ada di `requirements.md` sampai architect memindahkannya ke `docs/architecture.md`; setelah itu `requirements.md` dihapus.

## Fitur
| ID | Fitur | Kriteria terima | Data sensitif? |
| --- | --- | --- | --- |
| F1 | Fondasi: router, kernel, container, header keamanan, CSRF, migrasi 16 tabel, seed provider | Kriteria M1 di requirements §11 | tidak |
| F2 | Login lokal + sesi SSO (cookie `__Host-`), rate limit, ganti password | Kriteria M2 | ya (auth) |
| F3 | MFA TOTP + 10 kode cadangan, enkripsi secret dengan sodium | Kriteria M3 | ya (auth) |
| F4 | Inti OIDC: discovery, JWKS, `/authorize` (PKCE S256), `/token` (3 grant), `/userinfo`, `/introspect`, `/revoke`, rotasi kunci | Kriteria M4 | ya (token) |
| F5 | Federasi 9 provider + penautan akun (5 cabang `IdentityLinker`), halaman konfirmasi tautan & lengkapi email | Kriteria M5 | ya (data pribadi) |
| F6 | Logout RP-initiated + back-channel single logout dengan retry | Kriteria M6 | ya (sesi) |
| F7 | Halaman akun: profil, ganti password, MFA, akun tertaut, sesi aktif, keluar dari semua perangkat | Bagian dari M2, M3, M5, M6 | ya (data pribadi) |
| F8 | Halaman admin (9 modul, 3 role, step-up MFA, audit before/after) + `/install` | Kriteria M7 | ya (auth, secret) |
| F9 | Pengerasan & rilis: GC, daftar ancaman §10, `docs/DEPLOY.md`, `docs/CLIENT.md`, OIDC conformance | Kriteria M8 | tidak |
| F10 | UI berbahasa Indonesia bernama "BikininSSO" (logo teks), minimalis dengan aksen keluarga merah yang nyaman di mata (merah bata/rose redup, kontras AA, mode terang & gelap), tanpa framework CSS dan tanpa inline script | Semua halaman memakai satu stylesheet dan token warna yang sama | tidak |

## Cakupan prototype
- Hanya UI dan alur halaman: login, pilih provider, MFA, konfirmasi tautan, lengkapi email, error, "Anda sudah keluar", akun (F7), admin 9 modul (F8), `/install`.
- Data dummy di `prototype/mocks/` (array PHP/JSON), tanpa database, tanpa panggilan ke provider asli, tanpa kriptografi nyata.
- Struktur mengikuti requirements §9 (`sso/` + `public_html/`), dijalankan dengan `php -S`.

## Stack yang direncanakan
- PHP 8.2+ tanpa framework (`declare(strict_types=1)`, PSR-4/PSR-12), MariaDB 10.5+, `firebase/php-jwt`, PHPUnit.
- Shared hosting cPanel: tanpa Redis/queue/proses long-running; cron atau GC probabilistik.
- Tanpa payment gateway.

## Di luar cakupan (out of scope)
- Multi-tenant, klien pihak ketiga & halaman consent, implicit/password grant, front-channel logout, SAML, impersonasi user.
