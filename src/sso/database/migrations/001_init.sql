-- 001_init: 16 tabel (requirements.md §4)
SET NAMES utf8mb4;

-- ===== USER & IDENTITAS =====
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,                       -- claim "sub"
  email VARCHAR(255) NULL,
  email_verified_at DATETIME NULL,
  password_hash VARCHAR(255) NULL,              -- NULL = hanya login via provider
  name VARCHAR(150) NULL,
  avatar_url VARCHAR(500) NULL,
  mfa_secret_enc VARBINARY(255) NULL,
  mfa_enabled_at DATETIME NULL,
  status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_uuid (uuid),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE identity_providers (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(30) NOT NULL,
  display_name VARCHAR(60) NOT NULL,
  protocol ENUM('oidc','oauth2') NOT NULL,
  driver VARCHAR(30) NOT NULL,                  -- nama class di Federation/Drivers
  client_id VARCHAR(255) NOT NULL,
  client_secret_enc VARBINARY(1024) NULL,
  discovery_url VARCHAR(500) NULL,
  authorize_url VARCHAR(500) NULL,
  token_url VARCHAR(500) NULL,
  userinfo_url VARCHAR(500) NULL,
  scopes VARCHAR(255) NOT NULL,
  trust_email TINYINT(1) NOT NULL DEFAULT 0,
  extra_config JSON NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_idp_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider_id SMALLINT UNSIGNED NOT NULL,
  provider_user_id VARCHAR(255) NOT NULL,       -- id dari provider, BUKAN email
  email VARCHAR(255) NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  raw_profile JSON NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_identity (provider_id, provider_user_id),
  KEY idx_identity_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (provider_id) REFERENCES identity_providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE federation_states (
  state_hash CHAR(64) PRIMARY KEY,
  provider_id SMALLINT UNSIGNED NOT NULL,
  nonce VARCHAR(128) NULL,
  code_verifier VARCHAR(128) NULL,
  pending_request JSON NOT NULL,                -- parameter /authorize asli
  link_to_user_id BIGINT UNSIGNED NULL,         -- diisi saat menautkan dari halaman akun
  expires_at DATETIME NOT NULL,                 -- +10 menit
  KEY idx_fs_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== KLIEN =====
CREATE TABLE clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id VARCHAR(80) NOT NULL,
  client_secret_hash VARCHAR(255) NULL,         -- NULL = public client
  name VARCHAR(100) NOT NULL,
  type ENUM('confidential','public') NOT NULL,
  allowed_grants SET('authorization_code','refresh_token','client_credentials') NOT NULL,
  allowed_scopes VARCHAR(255) NOT NULL DEFAULT 'openid profile email',
  access_token_ttl INT UNSIGNED NOT NULL DEFAULT 900,
  refresh_token_ttl INT UNSIGNED NOT NULL DEFAULT 2592000,
  backchannel_logout_uri VARCHAR(500) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_client_id (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_redirect_uris (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  uri VARCHAR(500) NOT NULL,
  kind ENUM('login','post_logout') NOT NULL DEFAULT 'login',
  KEY idx_cru_client (client_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== SESI & TOKEN =====
CREATE TABLE sso_sessions (
  id_hash CHAR(64) PRIMARY KEY,                 -- hash nilai cookie
  sid CHAR(36) NOT NULL,                        -- claim "sid", publik
  user_id BIGINT UNSIGNED NOT NULL,
  auth_method VARCHAR(30) NOT NULL,             -- password, google, github, ...
  mfa_passed_at DATETIME NULL,                  -- dipakai juga untuk step-up admin
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_sid (sid),
  KEY idx_sess_user (user_id),
  KEY idx_sess_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_clients (
  session_id_hash CHAR(64) NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  first_login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id_hash, client_id),
  FOREIGN KEY (session_id_hash) REFERENCES sso_sessions(id_hash) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE auth_codes (
  code_hash CHAR(64) PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id_hash CHAR(64) NOT NULL,
  redirect_uri VARCHAR(500) NOT NULL,
  scope VARCHAR(255) NOT NULL,
  nonce VARCHAR(128) NULL,
  code_challenge VARCHAR(128) NOT NULL,
  code_challenge_method ENUM('S256') NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  KEY idx_code_expires (expires_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE refresh_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  family_id CHAR(36) NOT NULL,
  auth_code_hash CHAR(64) NULL,                 -- asal family, untuk pencabutan saat code reuse
  client_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id_hash CHAR(64) NULL,
  scope VARCHAR(255) NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  KEY idx_rt_family (family_id),
  KEY idx_rt_code (auth_code_hash),
  KEY idx_rt_user (user_id),
  KEY idx_rt_expires (expires_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE logout_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  sid CHAR(36) NOT NULL,
  user_uuid CHAR(36) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at DATETIME NOT NULL,
  delivered_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  KEY idx_ln_pending (delivered_at, next_try_at),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signing_keys (
  kid VARCHAR(64) PRIMARY KEY,                  -- JWK thumbprint (RFC 7638)
  alg ENUM('RS256') NOT NULL DEFAULT 'RS256',
  public_key_pem TEXT NOT NULL,
  private_key_path VARCHAR(255) NOT NULL,       -- file di luar public_html
  status ENUM('next','active','retiring','retired') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  retiring_at DATETIME NULL,
  retired_at DATETIME NULL,
  KEY idx_sk_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== ADMIN, KEAMANAN, AUDIT =====
CREATE TABLE admin_roles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  role ENUM('superadmin','operator','auditor') NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  name VARCHAR(64) PRIMARY KEY,
  value JSON NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(255) NOT NULL,
  ip_address VARBINARY(16) NOT NULL,
  success TINYINT(1) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_ident (identifier, attempted_at),
  KEY idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,                 -- pelaku (user atau admin)
  target_user_id BIGINT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  event VARCHAR(50) NOT NULL,
  ip_address VARBINARY(16) NULL,
  context JSON NULL,                            -- termasuk nilai before/after untuk aksi admin
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_event (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
