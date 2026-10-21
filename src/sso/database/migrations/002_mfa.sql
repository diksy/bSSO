-- 002_mfa: kode cadangan (HMAC) dan langkah TOTP terakhir (anti-replay)
ALTER TABLE users
  ADD COLUMN mfa_backup_codes JSON NULL AFTER mfa_enabled_at,
  ADD COLUMN mfa_last_step BIGINT NULL AFTER mfa_backup_codes;
