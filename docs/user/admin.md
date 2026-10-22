<!-- maks 140 baris -->
# Panduan Admin BikininSSO

Untuk pemasangan server (webroot, konfigurasi, database, cron, HTTPS, backup) ikuti `docs/DEPLOY.md`. Untuk pengembang aplikasi klien lihat `docs/CLIENT.md`. Dokumen ini fokus pada pemakaian halaman admin.

## 1. Instalasi: superadmin pertama

Pilih salah satu cara.

**A. Lewat SSH (disarankan, tanpa token)**
1. Masuk ke server, jalankan skrip `bin/create-admin.php` dengan opsi email Anda.
2. Isi kata sandi saat diminta.

**B. Lewat halaman /install (tanpa SSH)**
1. Minta pengelola server menyetel variabel lingkungan `BSSO_INSTALL_TOKEN` dengan nilai acak yang panjang. Tanpa token ini halaman `/install` selalu menampilkan 404 (aman secara bawaan).
2. Buka `/install`, isi token pemasangan, email superadmin, dan kata sandi (min. 12 karakter, ketik dua kali).
3. Klik "Buat superadmin". Halaman otomatis nonaktif sesudahnya.
4. Hapus token dari konfigurasi server.

Halaman hanya aktif selama belum ada admin sama sekali. Setelah itu:
1. Masuk dengan akun superadmin.
2. Aktifkan MFA di menu Akun. MFA wajib untuk membuka area admin.

## 2. Role dan hak akses

Ada tiga role. Pemberian role dilakukan superadmin di menu Role admin (pilih user, pilih role, "Beri role"; ubah atau cabut dari tabel admin). Anda tidak bisa mengubah role sendiri, dan superadmin terakhir tidak bisa dihapus.

| Modul | Superadmin | Operator | Auditor |
| --- | --- | --- | --- |
| Dasbor | Baca | Baca | Baca |
| Aplikasi klien | Kelola | – | Baca |
| Provider | Kelola | – | Baca |
| User & sesi | Kelola | Kelola (kecuali user ber-role admin) | Baca |
| Kunci | Kelola | – | Baca |
| Audit log | Baca + ekspor | Baca | Baca + ekspor |
| Pemeliharaan | Jalankan | Jalankan | – |
| Pengaturan | Kelola | – | Baca |
| Role admin | Kelola | – | – |

## 3. Step-up (verifikasi ulang)

Aksi sensitif (membuat atau mengubah klien, provider, status user, kunci, pengaturan, role) meminta kode TOTP baru.

1. Saat aksi diminta, Anda dialihkan ke halaman verifikasi.
2. Masukkan kode 6 digit terbaru.
3. Aksi dilanjutkan. Verifikasi berlaku singkat (maks. 5 menit).

## 4. Mengelola modul

**Aplikasi klien**
1. Menu Aplikasi klien, "Tambah klien".
2. Isi nama, redirect URI login (satu per baris), dan pengaturan lain. Mengubah redirect URI atau grant meminta verifikasi MFA ulang.
3. Simpan, lalu salin client secret yang tampil. Secret hanya ditampilkan sekali; simpan di tempat aman.
4. Tersedia: regenerasi secret, kirim logout token uji, dan nonaktifkan/aktifkan klien.

**Provider**
1. Menu Provider, pilih provider.
2. Isi client id dan secret dari konsol provider (disimpan terenkripsi). Daftarkan alamat callback `/federation/<slug>/callback` di sisi provider.
3. Gunakan "Uji koneksi", lalu aktifkan. Detail khusus Apple ada di `docs/DEPLOY.md`.

**User & sesi**
1. Menu User & sesi, cari user, buka Detail.
2. Aksi: nonaktifkan atau aktifkan akun, lepas akun tertaut, reset MFA, paksa reset kata sandi, cabut sesi tertentu.

**Kunci**
- Menampilkan kunci penandatangan aktif. Rotasi berjalan otomatis tiap 90 hari (lewat cron).

**Audit log**
- Menu Audit log: telusuri aksi admin dan peristiwa penting, termasuk nilai sebelum dan sesudah perubahan. Ekspor CSV tersedia untuk superadmin dan auditor.

**Pengaturan**: dibaca auditor dan superadmin; hanya superadmin yang bisa mengubah (dengan step-up).

## 5. Pemeliharaan

Bila server punya cron (lihat `docs/DEPLOY.md`), semuanya berjalan otomatis. Bila tidak, buka menu Pemeliharaan secara berkala (mis. harian):
1. "Jalankan GC": hapus sesi, kode, dan token kedaluwarsa.
2. "Retry notifikasi logout": kirim ulang notifikasi logout ke aplikasi yang gagal. Angka notifikasi gagal permanen tampil di halaman ini dan dasbor.
3. "Rotasi kunci terjadwal": cek apakah rotasi sudah jatuh tempo (butuh step-up).

## 6. Rotasi kunci

Rotasi biasa: menu Kunci, "Rotasi". Kunci lama tetap berlaku singkat agar token yang beredar tidak langsung gugur.

Rotasi darurat (kunci diduga bocor):
1. Menu Kunci, pilih rotasi darurat, ketik `ROTATE` untuk konfirmasi, lalu lewati step-up.
2. Semua token lama gugur; pengguna dan aplikasi harus masuk ulang.
3. Catat kejadian dan selidiki penyebabnya.

Selalu simpan backup terenkripsi folder kunci, berkas rahasia, dan dump database di luar server (lihat `docs/DEPLOY.md`, bagian Kunci dan backup).

## 7. Keamanan harian

- Jangan membagikan kata sandi, secret klien, atau kode cadangan lewat chat atau tiket.
- Berikan role seminimal mungkin; pakai Auditor bila hanya perlu melihat.
- Tinjau Audit log dan dasbor (login gagal 24 jam) secara berkala.
