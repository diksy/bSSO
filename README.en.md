# BikininSSO

An SSO server (OpenID Connect / OAuth 2.0) and federation broker written in plain PHP with no framework, designed to run on shared hosting (cPanel) with MariaDB.

*[Versi Bahasa Indonesia](README.md)*

> Status: initial release. It has passed per-module code review, static analysis, a ZAP baseline scan and concurrency tests on MariaDB. **It has not had a human penetration test.** Do one yourself before using it with real data (see [SECURITY.md](SECURITY.md)). Most project documentation under `docs/` is currently in Indonesian.

## Features

- OIDC Provider: authorization code + mandatory PKCE (S256), refresh tokens with rotation and reuse detection, JWKS, discovery, userinfo, RS256 tokens with `kid`.
- Single logout: central SSO session and back-channel logout with SSRF protections.
- Local login (email + Argon2id password), email verification, password reset, TOTP MFA with backup codes, step-up authentication for admin actions.
- Federated login: Google, Microsoft, GitHub, GitLab, Facebook, Apple, LinkedIn, Discord, X.
- Admin panel: users, OAuth clients, providers, key rotation, audit log, maintenance.
- Atomic login rate limiting, strict security headers (CSP, HSTS), fail-closed `/install`.
- Signing-key rotation and a data re-encryption tool (`bin/reencrypt.php`).

## Requirements

PHP >= 8.2 (sodium, openssl, pdo_mysql, curl, mbstring), MariaDB 10.5+ (InnoDB, utf8mb4), HTTPS in production.

## Quick start

```bash
cd src/sso
composer install
cp .env.example .env && chmod 600 .env   # fill it in (never commit); config/*.php is optional
php bin/migrate.php
php bin/create-admin.php --email=admin@example.com
```

Full deployment guide: [docs/DEPLOY.md](docs/DEPLOY.md). Client integration: [docs/CLIENT.md](docs/CLIENT.md). End-user and admin guides: [docs/user/](docs/user/README.md).

## Development

```bash
cd src/sso
composer test    # PHPUnit (in-memory SQLite, ~5 min because of Argon2id)
composer stan    # PHPStan level 6
composer cs      # PHP_CodeSniffer (PSR-12)
```

Optional tests against a real MariaDB: set `BSSO_TEST_DB=mariadb` plus `DB_USER`, `DB_PASS`, `DB_NAME` (the database name must end in `_test`; all its tables are dropped for every test).

## Repository layout

| Folder | Contents |
| --- | --- |
| `src/sso/` | Application (kept outside the web root): code, views, migrations, tests, `bin/` scripts |
| `src/public_html/` | Web root: `index.php`, assets |
| `docs/` | Architecture, technical spec ([requirements.md](docs/requirements.md)), decisions, security report, documentation |
| `prototype/` | Early UI prototype with dummy data (historical; not production code) |

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md), not through public issues.

## License

[GNU Affero General Public License v3.0 or later](LICENSE) (AGPL-3.0-or-later). If you run a modified version as a network service, you must offer its source code to the users of that service.
