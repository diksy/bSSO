# Kebijakan Keamanan / Security Policy

## Melaporkan kerentanan / Reporting a vulnerability

Jangan membuka issue publik untuk kerentanan. Gunakan fitur **Private vulnerability reporting** di tab *Security* repositori GitHub ini (*Report a vulnerability*). Sertakan langkah reproduksi, versi/commit, dan dampaknya. Jangan sertakan data pribadi atau kredensial nyata.

Do not open a public issue for a vulnerability. Use GitHub's **Private vulnerability reporting** (Security tab, *Report a vulnerability*) with reproduction steps, version/commit and impact. Do not include real personal data or credentials.

Kami berusaha menanggapi dalam 7 hari dan mengoordinasikan pengungkapan setelah perbaikan tersedia. / We aim to respond within 7 days and to coordinate disclosure once a fix is available.

## Cakupan dan status

Perangkat lunak ini diberikan apa adanya (lihat LICENSE). Hasil pemeriksaan internal ada di [docs/security-report.md](docs/security-report.md): tidak ada temuan kritis/tinggi/sedang terbuka; temuan rendah S4 (penguncian login per email dapat dipicu pihak lain) diterima. Belum dilakukan pentest oleh manusia, ZAP authenticated scan, maupun uji di HTTPS nyata. Operator disarankan melakukannya sebelum produksi, serta memeriksa kepatuhan UU Pelindungan Data Pribadi / GDPR yang berlaku.

## Untuk operator

- Jangan pernah meng-commit `config/secrets.php`, `config/database.php`, `config/app.php`, atau `keys/`.
- Atur `trusted_proxies` bila berada di belakang proxy/CDN (lihat docs/DEPLOY.md).
- Aktifkan `/install` hanya sementara (`BSSO_INSTALL_TOKEN`), atau buat admin lewat `bin/create-admin.php`.
