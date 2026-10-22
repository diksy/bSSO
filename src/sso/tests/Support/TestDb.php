<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Database;

/** Database SQLite in-memory dengan subset skema (BUKAN MariaDB): hanya untuk tes logika repository/controller. */
final class TestDb
{
    public static function create(): Database
    {
        if (getenv('BSSO_TEST_DB') === 'mariadb') {
            return self::createMariaDb();
        }
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(<<<'SQL'
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, email TEXT NULL UNIQUE, email_verified_at TEXT NULL,
  password_hash TEXT NULL, name TEXT NULL, avatar_url TEXT NULL, mfa_secret_enc BLOB NULL, mfa_enabled_at TEXT NULL,
  mfa_backup_codes TEXT NULL, mfa_last_step INTEGER NULL, status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE sso_sessions (
  id_hash TEXT PRIMARY KEY, sid TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL, auth_method TEXT NOT NULL,
  mfa_passed_at TEXT NULL, ip_address BLOB NULL, user_agent TEXT NULL, created_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL
);
CREATE TABLE clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NULL UNIQUE, client_secret_hash TEXT NULL, name TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'confidential', allowed_grants TEXT NOT NULL DEFAULT '',
  allowed_scopes TEXT NOT NULL DEFAULT 'openid profile email', access_token_ttl INTEGER NOT NULL DEFAULT 900,
  refresh_token_ttl INTEGER NOT NULL DEFAULT 2592000, backchannel_logout_uri TEXT NULL, is_enabled INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE client_redirect_uris (
  id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, uri TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'login',
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);
CREATE TABLE auth_codes (
  code_hash TEXT PRIMARY KEY, client_id INTEGER NOT NULL, user_id INTEGER NOT NULL, session_sid TEXT NOT NULL,
  redirect_uri TEXT NOT NULL, scope TEXT NOT NULL, nonce TEXT NULL, code_challenge TEXT NOT NULL,
  code_challenge_method TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT NULL,
  FOREIGN KEY (session_sid) REFERENCES sso_sessions(sid) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);
CREATE TABLE refresh_tokens (
  token_hash TEXT PRIMARY KEY, family_id TEXT NOT NULL, auth_code_hash TEXT NULL, client_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL, session_sid TEXT NULL, scope TEXT NOT NULL, issued_at TEXT NOT NULL, expires_at TEXT NOT NULL,
  used_at TEXT NULL, revoked_at TEXT NULL,
  FOREIGN KEY (session_sid) REFERENCES sso_sessions(sid) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);
CREATE TABLE session_clients (
  session_sid TEXT NOT NULL, client_id INTEGER NOT NULL, first_login_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (session_sid, client_id),
  FOREIGN KEY (session_sid) REFERENCES sso_sessions(sid) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);
CREATE TABLE logout_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, sid TEXT NOT NULL, user_uuid TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0, next_try_at TEXT NOT NULL, delivered_at TEXT NULL, last_error TEXT NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);
CREATE TABLE login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL, ip_address BLOB NOT NULL,
  success INTEGER NOT NULL, attempted_at TEXT NOT NULL
);
CREATE TABLE audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, target_user_id INTEGER NULL, client_id INTEGER NULL,
  event TEXT NOT NULL, ip_address BLOB NULL, context TEXT NULL, created_at TEXT NOT NULL
);
CREATE TABLE signing_keys (
  kid TEXT PRIMARY KEY, alg TEXT NOT NULL DEFAULT 'RS256', public_key_pem TEXT NOT NULL, private_key_path TEXT NOT NULL,
  status TEXT NOT NULL, created_at TEXT NOT NULL, activated_at TEXT NULL, retiring_at TEXT NULL, retired_at TEXT NULL
);
CREATE TABLE identity_providers (
  id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, protocol TEXT NOT NULL,
  driver TEXT NOT NULL, client_id TEXT NOT NULL, client_secret_enc BLOB NULL, discovery_url TEXT NULL, authorize_url TEXT NULL,
  token_url TEXT NULL, userinfo_url TEXT NULL, scopes TEXT NOT NULL, trust_email INTEGER NOT NULL DEFAULT 0,
  extra_config TEXT NULL, is_enabled INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE user_identities (
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider_id INTEGER NOT NULL, provider_user_id TEXT NOT NULL,
  email TEXT NULL, email_verified INTEGER NOT NULL DEFAULT 0, raw_profile TEXT NULL,
  linked_at TEXT NOT NULL, last_login_at TEXT NULL, UNIQUE (provider_id, provider_user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (provider_id) REFERENCES identity_providers(id)
);
CREATE TABLE federation_states (
  state_hash TEXT PRIMARY KEY, provider_id INTEGER NOT NULL, nonce TEXT NULL, code_verifier TEXT NULL,
  pending_request TEXT NOT NULL, link_to_user_id INTEGER NULL, expires_at TEXT NOT NULL
);
CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_by INTEGER NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE admin_roles (user_id INTEGER PRIMARY KEY, role TEXT NOT NULL, granted_by INTEGER NULL, granted_at TEXT NOT NULL);
SQL);

        return new Database([], $pdo);
    }

    /** Mode opt-in (BSSO_TEST_DB=mariadb): skema asli dari database/migrations pada DB bernama *_test (DB_HOST/PORT/NAME/USER/PASS). */
    private static function createMariaDb(): Database
    {
        $name = (string) getenv('DB_NAME');
        if (!str_ends_with($name, '_test')) {
            throw new \RuntimeException('DB_NAME harus berakhiran _test agar tabel aman dihapus.');
        }
        // Satu koneksi dipakai ulang: koneksi lama yang tertinggal menahan metadata lock dan menggantung DROP TABLE.
        static $db = null;
        $db ??= new Database([
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'name' => $name,
            'user' => (string) getenv('DB_USER'),
            'pass' => (string) getenv('DB_PASS'),
        ]);
        $pdo = $db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            foreach (explode(";\n", (string) file_get_contents($file)) as $statement) {
                $statement = rtrim(trim($statement), ';');
                if (trim((string) preg_replace('/^\s*--.*$/m', '', $statement)) !== '') {
                    $pdo->exec($statement);
                }
            }
        }

        return $db;
    }
}
