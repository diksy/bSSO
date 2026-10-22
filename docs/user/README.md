<!-- maks 60 baris -->
# BikininSSO – Panduan Pengguna

BikininSSO adalah layanan masuk tunggal (SSO) untuk organisasi Anda. Anda masuk sekali, lalu bisa memakai semua aplikasi organisasi tanpa login ulang. Anda bisa masuk dengan email dan kata sandi, atau lewat akun provider eksternal (sembilan provider tersedia, sesuai yang diaktifkan admin).

## Siapa membaca apa

| Anda adalah | Baca |
| --- | --- |
| Pengguna aplikasi | [pengguna.md](pengguna.md) |
| Admin atau operator SSO | [admin.md](admin.md) |
| Pemasang server | `docs/DEPLOY.md` |
| Pengembang aplikasi klien | `docs/CLIENT.md` dan halaman `/panduan-integrasi` di server SSO Anda |

## Mulai cepat (pengguna)

1. Buka halaman masuk BikininSSO, biasanya Anda diarahkan otomatis dari aplikasi yang ingin dipakai.
2. Belum punya akun? Pilih "Daftar", lalu verifikasi email Anda.
3. Masuk dengan email dan kata sandi, atau pilih salah satu provider di bagian "atau lanjutkan dengan".
4. Disarankan mengaktifkan MFA di menu Akun agar akun lebih aman.

## Mulai cepat (admin)

1. Pastikan server sudah terpasang sesuai `docs/DEPLOY.md`.
2. Buat superadmin pertama (lihat [admin.md](admin.md), bagian Instalasi).
3. Masuk, aktifkan MFA (wajib untuk area admin), lalu buka `/admin`.

## Bantuan

- Lupa kata sandi: gunakan "Lupa kata sandi?" di halaman masuk.
- Kehilangan perangkat MFA dan kode cadangan: hubungi admin untuk mengatur ulang MFA Anda.
- Akun tidak bisa masuk berulang kali: tunggu beberapa saat (ada pembatasan percobaan), lalu coba lagi atau hubungi admin.
