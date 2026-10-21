<!-- satu baris per tugas. Pemilik: orchestrator. Dibaca: builder (barisnya saja), reviewer -->
# Daftar Tugas

Status: todo · proses · review · selesai · eskalasi
Penolakan: jumlah review blocking (0–3)

| ID | Modul | Fitur | Kesulitan | Status | Penolakan | Hasil review / catatan non-blocking |
| --- | --- | --- | --- | --- | --- | --- |
| T0 | kontrak | F1–F8 | sedang | selesai | 1 | Lolos review ke-2. Non-blocking: tes urutan middleware (RequireAdmin sebelum RequireRecentAuth); T9/T10 verifikasi Csrf exclude + formPostCallback terikat sesi UI; rotateIfDue/Csrf/MaintenanceController::rotateKeys tidak diperiksa langsung |
| T1 | fondasi | F1, F10 | sedang | selesai | 1 | Lolos review ke-2. Non-blocking: Logger belum menyaring rahasia dari pesan exception; Markdown.php:113 `**` di URL menyisipkan <strong> ke href; CSP tanpa form-action disengaja (catat di decisions.md); T10: tombol cabut per sesi di user_detail.php:28-31 (rute sudah ada); ViewFormsTest rapuh (regex); .htaccess belum diuji di Apache; migrasi belum diuji di MariaDB |
| T2 | auth-lokal | F2, F7 | tinggi | selesai | 2 | Lolos review ke-3 (ditolak 2x: pra-pembajakan akun pending, ESCAPE MariaDB). Non-blocking: (kontrak, ke architect sebelum T3/T9) FK session_clients tanpa ON UPDATE CASCADE (001_init.sql:119) + session_id_hash auth_codes/refresh_tokens dangling; batasi frekuensi kirim ulang verifikasi per email; forgot/register ikut kuota IP login; tes hanya SQLite, belum MariaDB; suite ±57 dtk (Argon2id) |
| T3 | mfa | F3, F7 | tinggi | selesai | 0 | Review: 1 blocking dianulir orchestrator (kolom mfa_backup_codes/mfa_last_step ada di 002_mfa.sql; migrate.php menerapkan berurutan). Non-blocking: rate limit mfa:<id> blocked()+record() tak atomik (lolos sedikit >5), pre_mfa tak dibatasi per sesi UI; verifikasi di T5/T7: semua pemanggil SsoSessionManager::start(mfaPassed) tak melewati tantangan MFA, CSP img-src data: untuk QR; bin/reencrypt.php masih stub; tes Crypto via container belum ada; FOR UPDATE belum diuji di MariaDB |
| T4 | kunci-jwks | F4 | sedang | selesai | 0 | Lolos review ke-1. Non-blocking: KeyManager.php:178-209 pada instalasi kosong FOR UPDATE tak mengunci → dua rotateIfDue serentak bisa membuat dua kunci active (saran: lock via baris setting/GET_LOCK atau unique index status=active); generate() menulis file di dalam transaksi (file yatim bila rollback); T10: MaintenanceController::rotateKeys harus mencatat audit key.rotate_scheduled; rotation_days 90/overlap_days 2 hanya default di kode (belum seed settings) |
| T5 | oauth-flow | F4 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T6 | logout | F6, F7 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T7 | federasi-inti | F5, F7 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T8 | federasi-driver | F5 | sedang | todo | 0 | urutan & dependensi: architecture.md |
| T9 | admin-inti | F8 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T10 | admin-kelola | F8 | tinggi | todo | 0 | urutan & dependensi: architecture.md |
| T11 | pengerasan | F9 | sedang | todo | 0 | urutan & dependensi: architecture.md |
