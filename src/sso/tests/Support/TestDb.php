<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Database;

/** Database SQLite in-memory dengan subset skema (BUKAN MariaDB): hanya untuk tes logika repository/controller. */
final class TestDb
{
    public static function create(): Database
    {
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
CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL);
CREATE TABLE session_clients (
  session_sid TEXT NOT NULL, client_id INTEGER NOT NULL, first_login_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (session_sid, client_id),
  FOREIGN KEY (session_sid) REFERENCES sso_sessions(sid) ON DELETE CASCADE,
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
CREATE TABLE admin_roles (user_id INTEGER PRIMARY KEY, role TEXT NOT NULL, granted_by INTEGER NULL, granted_at TEXT NOT NULL);
SQL);

        return new Database([], $pdo);
    }
}
