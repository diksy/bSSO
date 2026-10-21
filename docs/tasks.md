<!-- satu baris per tugas. Pemilik: orchestrator. Dibaca: builder (barisnya saja), reviewer -->
# Daftar Tugas

Status: todo · proses · review · selesai · eskalasi
Penolakan: jumlah review blocking (0–3)

| ID | Modul | Fitur | Kesulitan | Status | Penolakan | Hasil review / catatan non-blocking |
| --- | --- | --- | --- | --- | --- | --- |
| T0 | kontrak | F1–F8 | sedang | selesai | 1 | Lolos review ke-2. Non-blocking: tes urutan middleware (RequireAdmin sebelum RequireRecentAuth); T9/T10 verifikasi Csrf exclude + formPostCallback terikat sesi UI; rotateIfDue/Csrf/MaintenanceController::rotateKeys tidak diperiksa langsung |
| T1 | fondasi | F1, F10 | sedang | selesai | 1 | Lolos review ke-2. Non-blocking: Logger belum menyaring rahasia dari pesan exception; Markdown.php:113 `**` di URL menyisipkan <strong> ke href; CSP tanpa form-action disengaja (catat di decisions.md); T10: tombol cabut per sesi di user_detail.php:28-31 (rute sudah ada); ViewFormsTest rapuh (regex); .htaccess belum diuji di Apache; migrasi belum diuji di MariaDB |
| T2 | auth-lokal | F2, F7 | tinggi | selesai | 2 | Lolos review ke-3 (ditolak 2x: pra-pembajakan akun pending, ESCAPE MariaDB). Non-blocking: (kontrak, ke architect sebelum T3/T9) FK session_clients tanpa ON UPDATE CASCADE (001_init.sql:119) + session_id_hash auth_codes/refresh_tokens dangling; batasi frekuensi kirim ulang verifikasi per email; forgot/register ikut kuota IP login; tes hanya SQLite, belum MariaDB; suite ±57 dtk (Argon2id) |
| T3 | mfa | F3, F7 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T4 | kunci-jwks | F4 | sedang | todo | 0 | urutan & dependensi: architecture.md |
| T5 | oauth-flow | F4 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T6 | logout | F6, F7 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T7 | federasi-inti | F5, F7 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T8 | federasi-driver | F5 | sedang | todo | 0 | urutan & dependensi: architecture.md |
| T9 | admin-inti | F8 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T10 | admin-kelola | F8 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T11 | pengerasan | F9 | sedang | todo | 0 | urutan & dependensi: architecture.md |
