<!-- maks 120 baris -->
# Panduan Pengguna BikininSSO

## 1. Daftar dan verifikasi email

1. Buka halaman masuk, pilih "Daftar".
2. Isi data yang diminta dan kata sandi (gunakan kata sandi yang panjang dan unik).
3. Buka kotak masuk email Anda dan klik tautan verifikasi. Tautan berlaku 24 jam.
4. Setelah terverifikasi, Anda bisa masuk. Jika tautan kedaluwarsa, daftar ulang atau hubungi admin.

## 2. Masuk dengan email dan kata sandi

1. Buka halaman masuk (atau buka aplikasi yang ingin dipakai).
2. Isi email dan kata sandi, lalu klik "Masuk".
3. Centang "Ingat saya di perangkat ini" hanya di perangkat pribadi.
4. Jika MFA aktif, masukkan kode saat diminta (lihat bagian 4).

Terlalu banyak percobaan gagal akan menahan login sementara.

## 3. Masuk lewat provider

1. Di halaman masuk, pilih provider di bagian "atau lanjutkan dengan".
2. Login di provider tersebut dan setujui.
3. Kemungkinan yang muncul setelahnya:
   - Langsung masuk, bila akun sudah tertaut.
   - Halaman konfirmasi tautan: email Anda sudah terdaftar di BikininSSO. Konfirmasi dengan kata sandi akun Anda agar kedua akun tertaut.
   - Halaman lengkapi email: provider tidak memberi email. Isi email Anda, lalu buka tautan verifikasi yang dikirim.

## 4. MFA (TOTP) dan kode cadangan

Aktifkan lewat Akun, lalu "MFA".

1. Pasang aplikasi autentikator di ponsel (misalnya FreeOTP atau Aegis).
2. Pindai kode QR, atau masukkan kunci manual yang ditampilkan.
3. Masukkan 6 digit yang muncul, lalu klik "Aktifkan MFA".
4. Anda akan menerima 10 kode cadangan. Simpan di tempat aman (pengelola kata sandi atau kertas). Setiap kode hanya berlaku sekali dan tidak ditampilkan lagi.

Saat masuk: masukkan 6 digit dari aplikasi, atau satu kode cadangan bila ponsel tidak ada.

Buat ulang kode cadangan atau nonaktifkan MFA dari halaman yang sama; keduanya meminta kata sandi Anda. Kode cadangan lama tidak berlaku setelah dibuat ulang.

## 5. Halaman akun

Buka `/account` atau menu Akun.

- **Profil**: ubah nama dan data profil Anda.
- **Ganti kata sandi**: isi kata sandi lama, lalu yang baru.
- **Akun tertaut**: lihat provider yang tertaut, tautkan provider baru, atau lepas tautan. Sisakan minimal satu cara masuk yang bisa Anda pakai.
- **Sesi aktif**: lihat perangkat yang sedang masuk. Klik "Cabut" untuk mengeluarkan satu sesi.
- **Keluar dari semua perangkat**: mengakhiri semua sesi sekaligus. Berguna bila perangkat hilang atau Anda curiga akun dipakai orang lain.

## 6. Lupa kata sandi

1. Di halaman masuk, klik "Lupa kata sandi?".
2. Isi email akun Anda.
3. Buka tautan di email yang masuk (waktunya terbatas) dan buat kata sandi baru.
4. Masuk dengan kata sandi baru. Bila email tidak datang, cek folder spam.

## 7. Keluar (logout)

1. Pilih "Keluar" di aplikasi atau di menu Akun, lalu konfirmasi.
2. Anda akan keluar dari BikininSSO dan aplikasi lain yang terhubung diberi tahu agar ikut mengakhiri sesi.
3. Halaman "Anda sudah keluar" menandakan proses selesai.

Di komputer bersama, selalu keluar setelah selesai dan jangan memakai "Ingat saya".
