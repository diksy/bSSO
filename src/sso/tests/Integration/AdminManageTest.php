<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Account\AdminCreator;
use App\Admin\ClientForm;
use App\Admin\InstallGuard;
use App\Controller\Admin\UsersController;
use App\Controller\InstallController;
use App\Http\Kernel;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repository\Record\User;
use App\Repository\AdminRoleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\AuthCodeRepository;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\FederationStateRepository;
use App\Repository\IdentityProviderRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\LogoutNotificationRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\SessionClientRepository;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\OAuth\TokenIssuer;
use App\Security\Crypto;
use App\Security\KeyManager;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SignedTokens;
use App\Security\Totp;
use App\Session\BackchannelNotifier;
use App\Session\SessionTerminator;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Container;
use App\Support\Database;
use App\Support\Gc;
use App\Support\HttpClient;
use App\Support\HttpResult;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\UiSession;
use App\Support\UrlAllowlist;
use App\Support\Urls;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** T10: kelola klien, provider, user & sesi, kunci, pemeliharaan. SQLite in-memory (BUKAN MariaDB). */
final class AdminManageTest extends TestCase
{
    private const DNS = [
        'rp.example.test' => ['93.184.216.34'],
        'idp.example.test' => ['93.184.216.35'],
        'evil.example.test' => ['169.254.169.254'],
    ];

    private Stack $s;
    private Kernel $kernel;
    private Container $c;
    private string $keyDir;
    private string $lock;
    private KeyManager $km;
    /** @var list<array<string,mixed>> */
    private array $sent = [];
    /** @var array<string,HttpResult> url => respons */
    private array $responses = [];
    /** @var array<int,string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        UiSession::fake();
        $s = $this->s = new Stack();
        $this->keyDir = sys_get_temp_dir() . '/bsso-manage-' . bin2hex(random_bytes(6));
        $this->lock = sys_get_temp_dir() . '/bsso-manage-install-' . bin2hex(random_bytes(4)) . '.lock';
        $c = $this->c = new Container();
        $c->set(Database::class, $s->db);
        $c->set(Clock::class, $s->clock);
        $c->set(View::class, $s->view);
        $c->set(Logger::class, $s->logger);
        $c->set(Audit::class, $s->audit);
        $c->set(Crypto::class, $s->crypto);
        $c->set(Totp::class, $s->totp);
        $c->set(RateLimiter::class, $s->limiter);
        $c->set(SsoSessionManager::class, $s->manager);
        $c->set(SessionTerminator::class, $s->terminator);
        $c->set(PasswordHasher::class, $s->hasher);
        $c->set(SignedTokens::class, $s->tokens);
        $c->set(Mailer::class, $s->mailer);
        $c->set(Urls::class, $s->urls);
        $c->set(InstallGuard::class, new InstallGuard($s->roles, $this->lock));
        $c->set(Gc::class, new Gc($s->db, $s->clock));
        $c->set(UserRepository::class, $s->users);
        $c->set(SsoSessionRepository::class, $s->sessions);
        $c->set(AdminRoleRepository::class, $s->roles);
        $c->set(AuditLogRepository::class, $s->auditLogs);
        foreach (
            [
                SettingRepository::class, ClientRepository::class, ClientRedirectUriRepository::class, LogoutNotificationRepository::class,
                SigningKeyRepository::class, IdentityProviderRepository::class, UserIdentityRepository::class, SessionClientRepository::class,
                RefreshTokenRepository::class, AuthCodeRepository::class, FederationStateRepository::class, LoginAttemptRepository::class,
            ] as $repo
        ) {
            $c->set($repo, new $repo($s->db));
        }
        $allow = new UrlAllowlist(static fn (string $h): array => self::DNS[$h] ?? []);
        $http = new HttpClient($allow, function (array $reqs): array {
            $out = [];
            foreach ($reqs as $key => $r) {
                $this->sent[] = $r;
                $out[$key] = $this->responses[$r['url']] ?? new HttpResult(204, '');
            }

            return $out;
        });
        $c->set(UrlAllowlist::class, $allow);
        $c->set(HttpClient::class, $http);
        $c->set(ClientForm::class, new ClientForm($allow, false));
        $this->km = new KeyManager(new SigningKeyRepository($s->db), new SettingRepository($s->db), $s->db, $s->clock, $s->audit, $this->keyDir);
        $this->km->rotateIfDue();
        $c->set(KeyManager::class, $this->km);
        $issuer = new TokenIssuer($this->km, $s->urls, $s->clock);
        $c->set(TokenIssuer::class, $issuer);
        $c->set(BackchannelNotifier::class, new BackchannelNotifier(
            new LogoutNotificationRepository($s->db),
            new SessionClientRepository($s->db),
            new ClientRepository($s->db),
            $s->users,
            $issuer,
            $http,
            $s->clock,
            $s->audit,
        ));
        $router = new Router();
        require __DIR__ . '/../../routes.php';
        $this->kernel = new Kernel($c, $router, $s->logger);
        $c->set(Kernel::class, $this->kernel);
        $c->set(Router::class, $router);
    }

    protected function tearDown(): void
    {
        UiSession::reset();
        @unlink($this->lock);
        foreach (glob($this->keyDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if (is_dir($this->keyDir)) {
            @rmdir($this->keyDir);
        }
    }

    // ---------- helpers ----------

    /** @return array{User, string} */
    private function admin(?string $role, bool $mfa = true): array
    {
        $s = $this->s;
        $u = $s->users->create(($role ?? 'biasa') . bin2hex(random_bytes(3)) . '@example.test', $s->hasher->hash('kata-sandi-panjang-1'), ucfirst($role ?? 'biasa'));
        $s->users->markEmailVerified($u->id, $s->clock->now());
        if ($mfa) {
            $secret = $s->totp->generateSecret();
            $s->users->enableMfa($u->id, $s->crypto->encrypt($secret), [], 1, $s->clock->now());
            $this->secrets[$u->id] = $secret;
        }
        if ($role !== null) {
            $s->roles->create($u->id, $role, null, $s->clock->now());
        }
        $cookie = $s->manager->start($u->id, 'password', true, new Request('POST', '/login', [], [], [], [], [], '198.51.100.7'));

        return [$s->users->findById($u->id) ?? $u, $cookie->value];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     */
    private function call(string $method, string $path, string $cookie, array $body = [], array $query = []): Response
    {
        if ($method === 'POST') {
            $body['_csrf'] = Csrf::currentToken();
        }

        return $this->kernel->handle(new Request($method, $path, $query, $body, ['user-agent' => 'PHPUnit'], [SsoSessionManager::COOKIE => $cookie], [], '198.51.100.7'));
    }

    private function rows(string $table, string $where = '1=1'): int
    {
        return (int) $this->s->db->pdo()->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();
    }

    private function auditCount(string $event): int
    {
        return $this->rows('audit_logs', "event = '" . $event . "'");
    }

    private function allAudit(): string
    {
        return implode("\n", array_map('strval', $this->s->db->pdo()->query('SELECT event || context FROM audit_logs')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function clientBody(array $o = []): array
    {
        return $o + [
            'name' => 'Aplikasi Uji', 'type' => 'confidential', 'grants' => ['authorization_code', 'refresh_token'],
            'scopes' => 'openid profile email', 'access_ttl' => '900', 'refresh_ttl' => '2592000',
            'redirect_login' => "https://rp.example.test/cb\nhttps://rp.example.test/cb2", 'redirect_logout' => 'https://rp.example.test/bye',
            'backchannel' => 'https://rp.example.test/bcl',
        ];
    }

    /** @param array<string,mixed> $o */
    private function createClient(string $cookie, array $o = []): string
    {
        $res = $this->call('POST', '/admin/clients', $cookie, $this->clientBody($o));
        self::assertSame(303, $res->status, $res->body);
        self::assertMatchesRegularExpression('#^/admin/clients/(cl_[0-9a-f]+)$#', $res->headers['Location']);

        return substr($res->headers['Location'], strlen('/admin/clients/'));
    }

    /** @param array<string,mixed> $o */
    private function seedProvider(string $slug, string $protocol = 'oidc', array $o = []): int
    {
        $o += [
            'discovery' => $protocol === 'oidc' ? 'https://idp.example.test/.well-known/openid-configuration' : null,
            'authorize' => $protocol === 'oauth2' ? 'https://idp.example.test/authorize' : null,
            'token' => $protocol === 'oauth2' ? 'https://idp.example.test/token' : null,
            'userinfo' => $protocol === 'oauth2' ? 'https://idp.example.test/me' : null,
        ];
        $st = $this->s->db->pdo()->prepare(
            'INSERT INTO identity_providers (slug, display_name, protocol, driver, client_id, discovery_url, authorize_url, token_url, userinfo_url, scopes) '
            . "VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, 'openid')",
        );
        $st->execute([$slug, ucfirst($slug), $protocol, ucfirst($slug) . 'Driver', $o['discovery'], $o['authorize'], $o['token'], $o['userinfo']]);

        return (int) $this->s->db->pdo()->lastInsertId();
    }

    private function p8(): string
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($k);
        openssl_pkey_export($k, $pem);

        return $pem;
    }

    // ---------- otorisasi ----------

    public function testOperatorDanAuditorTidakBisaAksiManageModulLain(): void
    {
        [, $op] = $this->admin('operator');
        [, $aud] = $this->admin('auditor');
        [, $sa] = $this->admin('superadmin');
        $id = $this->createClient($sa);
        $this->seedProvider('google');
        foreach (
            [
                ['GET', '/admin/clients'], ['POST', "/admin/clients/$id/toggle"], ['POST', "/admin/clients/$id/regenerate-secret"],
                ['POST', '/admin/providers/google/toggle'], ['POST', '/admin/providers/google/test-connection'],
                ['GET', '/admin/keys'], ['POST', '/admin/keys/rotate'], ['POST', '/admin/keys/rotate-emergency'],
            ] as [$m, $p]
        ) {
            self::assertSame(403, $this->call($m, $p, $op)->status, "operator $m $p");
        }
        foreach (
            [
                ['POST', "/admin/clients/$id/toggle"], ['POST', '/admin/providers/google/toggle'], ['POST', '/admin/keys/rotate'],
                ['POST', '/admin/maintenance/gc'], ['GET', '/admin/maintenance'], ['POST', '/admin/users/1/status'],
            ] as [$m, $p]
        ) {
            self::assertSame(403, $this->call($m, $p, $aud)->status, "auditor $m $p");
        }
        self::assertSame(200, $this->call('GET', '/admin/clients', $aud)->status);
        self::assertSame(200, $this->call('GET', '/admin/keys', $aud)->status);
        self::assertSame(200, $this->call('GET', '/admin/maintenance', $op)->status);
        $client = $this->c->get(ClientRepository::class)->findByClientId($id);
        self::assertTrue($client?->isEnabled);
    }

    // ---------- klien ----------

    public function testBuatKlienSecretHashDanTampilSekali(): void
    {
        [, $sa] = $this->admin('superadmin');
        $res = $this->call('POST', '/admin/clients', $sa, $this->clientBody());
        $id = substr($res->headers['Location'], strlen('/admin/clients/'));
        $secret = (string) ($_SESSION['one_time_secret']['secret'] ?? '');
        self::assertNotSame('', $secret);

        $client = $this->c->get(ClientRepository::class)->findByClientId($id);
        self::assertNotNull($client);
        self::assertNotSame($secret, $client->clientSecretHash);
        self::assertTrue($this->s->hasher->verify($secret, (string) $client->clientSecretHash));

        $first = $this->call('GET', $res->headers['Location'], $sa);
        self::assertStringContainsString($secret, $first->body, 'tampil sekali');
        $second = $this->call('GET', $res->headers['Location'], $sa);
        self::assertStringNotContainsString($secret, $second->body);
        self::assertStringNotContainsString($secret, $this->call('GET', '/admin/clients', $sa)->body);
        self::assertStringNotContainsString($secret, $this->allAudit());
        self::assertStringNotContainsString((string) $client->clientSecretHash, $this->call('GET', $res->headers['Location'], $sa)->body);
        self::assertSame(1, $this->auditCount('client.create'));
        $uris = $this->c->get(ClientRedirectUriRepository::class);
        self::assertCount(2, $uris->listForClient($client->id, 'login'));
    }

    public function testKlienPublicTanpaSecret(): void
    {
        [, $sa] = $this->admin('superadmin');
        $id = $this->createClient($sa, ['type' => 'public', 'grants' => ['authorization_code']]);
        self::assertArrayNotHasKey('one_time_secret', $_SESSION);
        self::assertNull($this->c->get(ClientRepository::class)->findByClientId($id)?->clientSecretHash);
        $this->call('POST', "/admin/clients/$id/regenerate-secret", $sa);
        self::assertArrayNotHasKey('one_time_secret', $_SESSION);
        $bad = $this->call('POST', '/admin/clients', $sa, $this->clientBody(['type' => 'public', 'grants' => ['client_credentials']]));
        self::assertSame(422, $bad->status);
    }

    public function testValidasiRedirectUriDanBackchannel(): void
    {
        [, $sa] = $this->admin('superadmin');
        $cases = [
            'http non-localhost' => ['redirect_login' => 'http://rp.example.test/cb'],
            'http localhost (non-dev)' => ['redirect_login' => 'http://localhost:3000/cb'],
            'fragment' => ['redirect_login' => 'https://rp.example.test/cb#x'],
            'wildcard' => ['redirect_login' => 'https://*.example.test/cb'],
            'userinfo' => ['redirect_login' => 'https://u:p@rp.example.test/cb'],
            'javascript' => ['redirect_login' => 'javascript:alert(1)'],
            'logout http' => ['redirect_logout' => 'http://rp.example.test/bye'],
            'backchannel http' => ['backchannel' => 'http://rp.example.test/bcl'],
            'backchannel metadata IP' => ['backchannel' => 'https://169.254.169.254/latest'],
            'backchannel loopback' => ['backchannel' => 'https://127.0.0.1/x'],
            'backchannel DNS ke IP internal' => ['backchannel' => 'https://evil.example.test/x'],
            'backchannel port' => ['backchannel' => 'https://rp.example.test:8443/x'],
            'backchannel tak ter-resolve' => ['backchannel' => 'https://nohost.example.test/x'],
            'tanpa redirect' => ['redirect_login' => ''],
        ];
        foreach ($cases as $label => $o) {
            $res = $this->call('POST', '/admin/clients', $sa, $this->clientBody($o));
            self::assertSame(422, $res->status, $label);
        }
        self::assertSame(0, $this->rows('clients'));
        self::assertSame(0, $this->rows('client_redirect_uris'));
    }

    public function testLocalhostHttpHanyaUntukDev(): void
    {
        $allow = new UrlAllowlist(static fn (string $h): array => self::DNS[$h] ?? []);
        $body = $this->clientBody(['redirect_login' => 'http://localhost:3000/cb', 'backchannel' => '']);
        $dev = new ClientForm($allow, true);
        self::assertSame(['http://localhost:3000/cb'], $dev->parse($body)['login']);
        $this->expectException(\InvalidArgumentException::class);
        (new ClientForm($allow, false))->parse($body);
    }

    public function testUbahKlienAuditBeforeAfterDanStepUp(): void
    {
        [$u, $sa] = $this->admin('superadmin');
        $id = $this->createClient($sa);
        $this->s->clock->advance('+6 minutes');
        $res = $this->call('POST', "/admin/clients/$id", $sa, $this->clientBody(['name' => 'Baru', 'redirect_login' => 'https://rp.example.test/baru']));
        self::assertSame('/admin/stepup', $res->headers['Location'], 'ubah redirect/grant wajib step-up');
        self::assertSame('Aplikasi Uji', $this->c->get(ClientRepository::class)->findByClientId($id)?->name);

        $ok = $this->call('POST', '/admin/stepup', $sa, ['code' => $this->code($u)]);
        self::assertSame("/admin/clients/$id", $ok->headers['Location']);
        $client = $this->c->get(ClientRepository::class)->findByClientId($id);
        self::assertSame('Baru', $client?->name);
        self::assertSame(['https://rp.example.test/baru'], array_map(static fn ($r) => $r->uri, $this->c->get(ClientRedirectUriRepository::class)->listForClient($client->id, 'login')));
        $ctx = json_decode((string) $this->s->db->pdo()->query("SELECT context FROM audit_logs WHERE event = 'client.update'")->fetchColumn(), true);
        self::assertSame('Aplikasi Uji', $ctx['before']['name']);
        self::assertSame('Baru', $ctx['after']['name']);
        self::assertSame(['https://rp.example.test/baru'], $ctx['after']['redirect_login']);
    }

    private function code(User $u): string
    {
        $step = intdiv($this->s->clock->now()->getTimestamp(), 30);

        return $this->s->totp->code(Totp::base32Decode($this->secrets[$u->id]), $step);
    }

    public function testKlienTakDikenalDanTipeTakBerubah(): void
    {
        [, $sa] = $this->admin('superadmin');
        self::assertSame(404, $this->call('GET', '/admin/clients/cl_tidakada', $sa)->status);
        self::assertSame(404, $this->call('POST', '/admin/clients/cl_tidakada/toggle', $sa)->status);
        $id = $this->createClient($sa);
        $this->call('POST', "/admin/clients/$id", $sa, $this->clientBody(['type' => 'public']));
        self::assertSame('confidential', $this->c->get(ClientRepository::class)->findByClientId($id)?->type);
    }

    public function testRegenerasiSecretTampilSekaliDanSecretLamaMati(): void
    {
        [, $sa] = $this->admin('superadmin');
        $id = $this->createClient($sa);
        $old = (string) $_SESSION['one_time_secret']['secret'];
        UiSession::forget('one_time_secret');

        $this->s->clock->advance('+6 minutes');
        self::assertSame('/admin/stepup', $this->call('POST', "/admin/clients/$id/regenerate-secret", $sa)->headers['Location']);
        $this->s->clock->advance('-6 minutes');
        $this->call('POST', "/admin/clients/$id/regenerate-secret", $sa);
        $new = (string) $_SESSION['one_time_secret']['secret'];
        self::assertNotSame($old, $new);
        $hash = (string) $this->c->get(ClientRepository::class)->findByClientId($id)?->clientSecretHash;
        self::assertTrue($this->s->hasher->verify($new, $hash));
        self::assertFalse($this->s->hasher->verify($old, $hash));
        self::assertStringContainsString($new, $this->call('GET', "/admin/clients/$id", $sa)->body);
        self::assertStringNotContainsString($new, $this->call('GET', "/admin/clients/$id", $sa)->body);
        self::assertStringNotContainsString($new, $this->allAudit());
        self::assertSame(1, $this->auditCount('client.secret_regenerated'));
    }

    public function testToggleKlienDanLogoutUjiLewatAllowlist(): void
    {
        [, $sa] = $this->admin('superadmin');
        $id = $this->createClient($sa);
        $this->call('POST', "/admin/clients/$id/toggle", $sa);
        self::assertFalse($this->c->get(ClientRepository::class)->findByClientId($id)?->isEnabled);

        $this->call('POST', "/admin/clients/$id/test-logout", $sa);
        self::assertCount(1, $this->sent);
        self::assertSame('https://rp.example.test/bcl', $this->sent[0]['url']);
        self::assertSame('93.184.216.34', $this->sent[0]['ip'], 'IP disematkan');

        // SSRF: backchannel diubah langsung di DB ke host internal -> diblokir, tidak ada permintaan.
        $this->s->db->pdo()->exec("UPDATE clients SET backchannel_logout_uri = 'https://evil.example.test/x'");
        $this->resetSent();
        $this->call('POST', "/admin/clients/$id/test-logout", $sa);
        self::assertSame(0, $this->sentCount());
        $this->s->db->pdo()->exec("UPDATE clients SET backchannel_logout_uri = 'https://169.254.169.254/x'");
        $sebelum = $this->sentCount();
        $this->call('POST', "/admin/clients/$id/test-logout", $sa);
        self::assertSame($sebelum, $this->sentCount(), 'permintaan ke metadata IP tidak boleh terkirim');
    }

    // ---------- provider ----------

    public function testSecretProviderTerenkripsiWriteOnlyDanTakDiAudit(): void
    {
        [, $sa] = $this->admin('superadmin');
        $this->seedProvider('google');
        $secret = 'GOCSPX-rahasia-super-panjang';
        $res = $this->call('POST', '/admin/providers/google', $sa, [
            'active' => '1', 'order' => '2', 'client_id' => 'abc.apps.example', 'secret' => $secret, 'trust_email' => '1', 'extra' => '',
        ]);
        self::assertSame(303, $res->status, $res->body);
        $p = $this->c->get(IdentityProviderRepository::class)->findBySlug('google');
        self::assertNotNull($p);
        self::assertTrue($p->isEnabled);
        self::assertStringNotContainsString($secret, (string) $p->clientSecretEnc);
        self::assertSame($secret, $this->s->crypto->decrypt((string) $p->clientSecretEnc));
        self::assertStringNotContainsString($secret, $this->call('GET', '/admin/providers/google', $sa)->body);
        self::assertStringNotContainsString((string) $p->clientSecretEnc, $this->call('GET', '/admin/providers/google', $sa)->body);
        self::assertStringNotContainsString($secret, $this->allAudit());
        $ctx = json_decode((string) $this->s->db->pdo()->query("SELECT context FROM audit_logs WHERE event = 'provider.update'")->fetchColumn(), true);
        self::assertSame('', $ctx['before']['client_id']);
        self::assertSame('abc.apps.example', $ctx['after']['client_id']);

        // secret kosong = pertahankan
        $this->call('POST', '/admin/providers/google', $sa, ['active' => '1', 'order' => '2', 'client_id' => 'abc.apps.example', 'secret' => '', 'extra' => '']);
        self::assertSame($secret, $this->s->crypto->decrypt((string) $this->c->get(IdentityProviderRepository::class)->findBySlug('google')?->clientSecretEnc));
    }

    public function testAktifkanProviderTanpaKredensialDitolak(): void
    {
        [, $sa] = $this->admin('superadmin');
        $this->seedProvider('google');
        self::assertSame(422, $this->call('POST', '/admin/providers/google', $sa, ['active' => '1', 'order' => '1', 'client_id' => '', 'secret' => '', 'extra' => ''])->status);
        $this->call('POST', '/admin/providers/google/toggle', $sa);
        self::assertFalse($this->c->get(IdentityProviderRepository::class)->findBySlug('google')?->isEnabled);
        self::assertSame(404, $this->call('POST', '/admin/providers/tidakada/toggle', $sa)->status);
    }

    public function testExtraConfigDaftarPutihEmailsUrlGitHubDitolak(): void
    {
        [, $sa] = $this->admin('superadmin');
        $this->seedProvider('github', 'oauth2');
        $b = ['order' => '1', 'client_id' => 'x', 'secret' => 's'];
        foreach (
            [
                '{"emails_url":"https://evil.example.test/steal"}', '{"issuer":"https://x"}', '[1]', 'bukan json', '{"team_id":"A"}',
            ] as $extra
        ) {
            self::assertSame(422, $this->call('POST', '/admin/providers/github', $sa, $b + ['extra' => $extra])->status, $extra);
        }
        self::assertNull($this->c->get(IdentityProviderRepository::class)->findBySlug('github')?->extraConfig['emails_url'] ?? null);
    }

    public function testFacebookVersiGraphApiMengaturUrl(): void
    {
        [, $sa] = $this->admin('superadmin');
        $this->seedProvider('facebook', 'oauth2');
        $this->call('POST', '/admin/providers/facebook', $sa, ['order' => '6', 'client_id' => 'fb', 'secret' => 's', 'extra' => '{"graph_version":"v24.0"}']);
        $p = $this->c->get(IdentityProviderRepository::class)->findBySlug('facebook');
        self::assertSame('v24.0', $p?->extraConfig['graph_version']);
        self::assertSame('https://graph.facebook.com/v24.0/oauth/access_token', $p->tokenUrl);
        self::assertStringContainsString('/v24.0/me?', (string) $p->userinfoUrl);
        self::assertSame(422, $this->call('POST', '/admin/providers/facebook', $sa, ['order' => '6', 'client_id' => 'fb', 'extra' => '{"graph_version":"../evil"}'])->status);
    }

    public function testUploadP8AppleValidasiDanTerenkripsi(): void
    {
        [, $sa] = $this->admin('superadmin');
        $this->seedProvider('apple');
        $pem = $this->p8();
        $b = ['order' => '3', 'client_id' => 'com.example.sso', 'extra' => '{"team_id":"TEAM123456","key_id":"KEY1234567"}', 'active' => '1'];
        $res = $this->call('POST', '/admin/providers/apple', $sa, $b + ['p8_pem' => $pem]);
        self::assertSame(303, $res->status, $res->body);
        $p = $this->c->get(IdentityProviderRepository::class)->findBySlug('apple');
        self::assertTrue($p?->isEnabled);
        self::assertStringNotContainsString('PRIVATE KEY', (string) $p->clientSecretEnc);
        self::assertSame(trim($pem), trim($this->s->crypto->decrypt((string) $p->clientSecretEnc)));
        $page = $this->call('GET', '/admin/providers/apple', $sa)->body;
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $page);
        self::assertStringNotContainsString(substr($pem, 40, 30), $this->allAudit());

        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($rsa, $rsaPem);
        $p256k1 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        openssl_pkey_export($p256k1, $p384);
        foreach (['bukan pem', $rsaPem, $p384, $pem . "\n-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----", str_repeat('A', 5000)] as $bad) { // gitleaks:allow
            $before = (string) $this->c->get(IdentityProviderRepository::class)->findBySlug('apple')?->clientSecretEnc;
            self::assertSame(422, $this->call('POST', '/admin/providers/apple', $sa, $b + ['p8_pem' => $bad])->status);
            self::assertSame($before, (string) $this->c->get(IdentityProviderRepository::class)->findBySlug('apple')?->clientSecretEnc);
        }
        // tanpa team_id/key_id tidak boleh aktif
        self::assertSame(422, $this->call('POST', '/admin/providers/apple', $sa, ['active' => '1', 'order' => '3', 'client_id' => 'x', 'extra' => '', 'p8_pem' => $pem])->status);
    }

    public function testTestConnectionOidcDanSsrf(): void
    {
        [, $sa] = $this->admin('superadmin');
        $this->seedProvider('google');
        $disc = 'https://idp.example.test/.well-known/openid-configuration';
        $this->responses[$disc] = new HttpResult(200, (string) json_encode(['issuer' => 'https://idp.example.test', 'jwks_uri' => 'https://idp.example.test/jwks']));
        $this->responses['https://idp.example.test/jwks'] = new HttpResult(200, (string) json_encode(['keys' => [['kid' => 'a'], ['kid' => 'b']]]));
        $this->call('POST', '/admin/providers/google/test-connection', $sa);
        self::assertCount(2, $this->sent);
        self::assertStringContainsString('2 kunci', (string) json_encode($_SESSION['flash'] ?? []));

        // discovery menunjuk jwks_uri ke metadata cloud: HttpClient menolak, tidak ada permintaan ke sana.
        $this->sent = [];
        $_SESSION['flash'] = [];
        $this->responses[$disc] = new HttpResult(200, (string) json_encode(['issuer' => 'https://idp.example.test', 'jwks_uri' => 'https://169.254.169.254/jwks']));
        $this->call('POST', '/admin/providers/google/test-connection', $sa);
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('url_blocked', (string) json_encode($_SESSION['flash']));

        // discovery_url di DB ke host internal
        $this->sent = [];
        $this->s->db->pdo()->exec("UPDATE identity_providers SET discovery_url = 'https://evil.example.test/d'");
        $this->call('POST', '/admin/providers/google/test-connection', $sa);
        self::assertSame(0, $this->sentCount());
    }

    public function testTestConnectionOauth2MemeriksaAllowlist(): void
    {
        [, $sa] = $this->admin('superadmin');
        $this->seedProvider('github', 'oauth2', ['token' => 'https://169.254.169.254/token']);
        $this->call('POST', '/admin/providers/github/test-connection', $sa);
        self::assertSame(0, $this->sentCount());
        $this->s->db->pdo()->exec("UPDATE identity_providers SET token_url = 'https://idp.example.test/token'");
        $this->call('POST', '/admin/providers/github/test-connection', $sa);
        self::assertCount(1, $this->sent);
    }

    // ---------- user & sesi ----------

    /** @return array{User, string} user biasa dengan satu sesi */
    private function plainUser(bool $password = true): array
    {
        $s = $this->s;
        $u = $s->users->create('biasa' . bin2hex(random_bytes(3)) . '@example.test', $password ? $s->hasher->hash('kata-sandi-panjang-1') : null, 'Biasa');
        $s->users->markEmailVerified($u->id, $s->clock->now());
        $cookie = $s->manager->start($u->id, 'password', false, new Request('POST', '/login', [], [], [], [], [], '198.51.100.8'));

        return [$s->users->findById($u->id) ?? $u, $cookie->value];
    }

    private function sessionAlive(string $cookieValue): bool
    {
        return $this->s->manager->current(new Request('GET', '/', [], [], [], [SsoSessionManager::COOKIE => $cookieValue])) !== null;
    }

    public function testSuspendMencabutSesiAuditDanAktifkanKembali(): void
    {
        [, $op] = $this->admin('operator');
        [$t, $tc] = $this->plainUser();
        $this->call('POST', "/admin/users/{$t->id}/status", $op);
        self::assertSame('suspended', $this->s->users->findById($t->id)?->status);
        self::assertFalse($this->sessionAlive($tc), 'sesi user dicabut');
        $ctx = json_decode((string) $this->s->db->pdo()->query("SELECT context FROM audit_logs WHERE event = 'user.status'")->fetchColumn(), true);
        self::assertSame(['status' => 'active'], $ctx['before']);
        self::assertSame(['status' => 'suspended'], $ctx['after']);
        $this->call('POST', "/admin/users/{$t->id}/status", $op);
        self::assertSame('active', $this->s->users->findById($t->id)?->status);
    }

    public function testUserPendingTidakBisaDiaktifkanAdmin(): void
    {
        [, $sa] = $this->admin('superadmin');
        $p = $this->s->users->create('pending@example.test', null, 'P', 'pending');
        $this->call('POST', "/admin/users/{$p->id}/status", $sa);
        self::assertSame('pending', $this->s->users->findById($p->id)?->status);
    }

    public function testOperatorTidakBisaMenyentuhUserAdminDanIdor(): void
    {
        [, $op] = $this->admin('operator');
        [$sa] = $this->admin('superadmin');
        foreach (['status', 'reset-mfa', 'force-reset'] as $a) {
            self::assertSame(403, $this->call('POST', "/admin/users/{$sa->id}/$a", $op)->status, $a);
        }
        self::assertSame('active', $this->s->users->findById($sa->id)?->status);
        self::assertNotNull($this->s->users->findById($sa->id)->mfaEnabledAt);
        self::assertSame(3, $this->auditCount('user.manage_denied'));
        // detail tampil hanya-baca (tanpa tombol aksi)
        $page = $this->call('GET', "/admin/users/{$sa->id}", $op);
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('/reset-mfa', $page->body);
        // id tidak ada / bukan angka
        self::assertSame(404, $this->call('POST', '/admin/users/99999/status', $op)->status);
        self::assertSame(404, $this->call('GET', '/admin/users/abc', $op)->status);
        self::assertSame(404, $this->call('POST', '/admin/users/1%20OR%201=1/status', $op)->status);
    }

    public function testAdminTidakBisaMenonaktifkanDiriSendiri(): void
    {
        [$a, $sa] = $this->admin('superadmin');
        $this->admin('superadmin');
        $this->call('POST', "/admin/users/{$a->id}/status", $sa);
        self::assertSame('active', $this->s->users->findById($a->id)?->status);
        $this->call('POST', "/admin/users/{$a->id}/reset-mfa", $sa);
        self::assertNotNull($this->s->users->findById($a->id)->mfaEnabledAt);
    }

    public function testSuperadminTerakhirTidakBisaDisuspend(): void
    {
        $s = $this->s;
        [$last] = $this->admin('superadmin');
        $stale = $s->users->create('stale@example.test', null, 'Stale');
        $ctl = $this->usersController();
        $req = (new Request('POST', '/admin/users/x/status', [], []))
            ->withAttribute('user', $stale)->withAttribute('admin_role', 'superadmin')->withAttribute('id', (string) $last->id);
        $ctl->setStatus($req);
        self::assertSame('active', $s->users->findById($last->id)->status);
        self::assertSame(0, $this->auditCount('user.status'));

        // dengan superadmin lain yang aktif, boleh
        [$other] = $this->admin('superadmin');
        $ctl->setStatus($req);
        self::assertSame('suspended', $s->users->findById($last->id)->status);
        // lalu satu-satunya superadmin aktif ($other) dilindungi
        $req2 = $req->withAttribute('id', (string) $other->id);
        $ctl->setStatus($req2);
        self::assertSame('active', $s->users->findById($other->id)?->status);
    }

    private function usersController(): UsersController
    {
        return $this->c->make(UsersController::class);
    }

    public function testResetMfaMencabutSesiDanAudit(): void
    {
        [, $op] = $this->admin('operator');
        [$t, $tc] = $this->plainUser();
        $this->s->users->enableMfa($t->id, $this->s->crypto->encrypt('JBSWY3DPEHPK3PXP'), ['h'], 1, $this->s->clock->now());
        $this->call('POST', "/admin/users/{$t->id}/reset-mfa", $op);
        $u = $this->s->users->findById($t->id);
        self::assertNull($u?->mfaEnabledAt);
        self::assertNull($u?->mfaSecretEnc);
        self::assertFalse($this->sessionAlive($tc));
        self::assertSame(1, $this->auditCount('user.mfa_reset'));
    }

    public function testPaksaResetPasswordKirimEmailDanCabutSesi(): void
    {
        [, $op] = $this->admin('operator');
        [$t, $tc] = $this->plainUser();
        $this->call('POST', "/admin/users/{$t->id}/force-reset", $op);
        self::assertCount(1, $this->s->mailer->sent);
        self::assertSame($t->email, $this->s->mailer->sent[0]['to']);
        $payload = $this->s->tokens->verify(SignedTokens::RESET, $this->s->mailer->lastToken());
        self::assertSame($t->id, $payload['uid'] ?? null);
        self::assertFalse($this->sessionAlive($tc));
        self::assertStringNotContainsString($this->s->mailer->lastToken(), $this->allAudit());
    }

    public function testPutuskanTautanMenjagaCaraMasuk(): void
    {
        [, $op] = $this->admin('operator');
        $gid = $this->seedProvider('google');
        $hid = $this->seedProvider('github', 'oauth2');
        [$onlyIdp] = $this->plainUser(false);
        $ids = new UserIdentityRepository($this->s->db);
        $ids->create($onlyIdp->id, $gid, 'g-1', null, false, null, $this->s->clock->now());
        $this->call('POST', "/admin/users/{$onlyIdp->id}/unlink/google", $op);
        self::assertCount(1, $ids->listForUser($onlyIdp->id), 'tautan terakhir tanpa password dipertahankan');

        $ids->create($onlyIdp->id, $hid, 'h-1', null, false, null, $this->s->clock->now());
        $this->call('POST', "/admin/users/{$onlyIdp->id}/unlink/google", $op);
        self::assertCount(1, $ids->listForUser($onlyIdp->id));
        self::assertSame(1, $this->auditCount('identity.unlinked_by_admin'));

        [$withPw] = $this->plainUser();
        $ids->create($withPw->id, $gid, 'g-2', null, false, null, $this->s->clock->now());
        $this->call('POST', "/admin/users/{$withPw->id}/unlink/google", $op);
        self::assertCount(0, $ids->listForUser($withPw->id));
        // IDOR: tautan milik user lain tidak bisa dihapus lewat URL user ini
        self::assertSame(404, $this->call('POST', "/admin/users/{$withPw->id}/unlink/github", $op)->status);
        self::assertCount(1, $ids->listForUser($onlyIdp->id));
    }

    public function testCabutSesiPerUserIdorDanTombolDiDetail(): void
    {
        [, $op] = $this->admin('operator');
        [$a, $ac] = $this->plainUser();
        [$b, $bc] = $this->plainUser();
        $sidA = $this->s->sessions->listActiveForUser($a->id, $this->s->clock->now())[0]->sid;
        $sidB = $this->s->sessions->listActiveForUser($b->id, $this->s->clock->now())[0]->sid;

        $page = $this->call('GET', "/admin/users/{$a->id}", $op)->body;
        self::assertStringContainsString("/admin/users/{$a->id}/sessions/$sidA/revoke", $page);
        self::assertStringNotContainsString($sidB, $page);

        self::assertSame(404, $this->call('POST', "/admin/users/{$a->id}/sessions/$sidB/revoke", $op)->status, 'sesi user lain');
        self::assertTrue($this->sessionAlive($bc));
        self::assertSame(404, $this->call('POST', "/admin/users/{$a->id}/sessions/bukan-sid/revoke", $op)->status);
        $res = $this->call('POST', "/admin/users/{$a->id}/sessions/$sidA/revoke", $op);
        self::assertSame("/admin/users/{$a->id}", $res->headers['Location']);
        self::assertFalse($this->sessionAlive($ac));
        self::assertSame(1, $this->auditCount('session.admin_revoke'));
        self::assertSame(404, $this->call('POST', "/admin/users/{$a->id}/sessions/$sidA/revoke", $op)->status, 'sudah dicabut');
    }

    public function testAksiStepUpUserWajibStepUp(): void
    {
        [, $op] = $this->admin('operator');
        [$t] = $this->plainUser();
        $this->s->clock->advance('+6 minutes');
        foreach (['status', 'reset-mfa', 'force-reset'] as $a) {
            self::assertSame('/admin/stepup', $this->call('POST', "/admin/users/{$t->id}/$a", $op)->headers['Location'], $a);
        }
        self::assertSame('active', $this->s->users->findById($t->id)?->status);
    }

    public function testCariUserDanRolesCari(): void
    {
        [, $sa] = $this->admin('superadmin');
        for ($i = 0; $i < 60; $i++) {
            $this->s->users->create("massal$i@example.test", null, 'Massal');
        }
        $this->s->users->create('langka-xyz@example.test', null, 'Langka');
        self::assertStringContainsString('langka-xyz@example.test', $this->call('GET', '/admin/users', $sa, [], ['q' => 'langka'])->body);
        self::assertStringNotContainsString('massal5@example.test', $this->call('GET', '/admin/users', $sa, [], ['q' => 'langka'])->body);
        // pemilih "Beri role" mencari, bukan 200 pertama
        self::assertStringContainsString('langka-xyz@example.test', $this->call('GET', '/admin/roles', $sa, [], ['q' => 'langka'])->body);
    }

    // ---------- kunci ----------

    public function testRotasiPaksaWajibStepUpAuditDanDarurat(): void
    {
        [$u, $sa] = $this->admin('superadmin');
        $keys = new SigningKeyRepository($this->s->db);
        $this->s->clock->advance('+6 minutes');
        self::assertSame('/admin/stepup', $this->call('POST', '/admin/keys/rotate', $sa)->headers['Location']);
        self::assertSame([], $keys->listByStatus('next'));
        $this->call('POST', '/admin/stepup', $sa, ['code' => $this->code($u)]);
        self::assertCount(1, $keys->listByStatus('next'), 'rotasi dijalankan setelah step-up');
        self::assertSame(1, $this->auditCount('key.rotate'));

        $active = $keys->findActive()?->kid;
        $res = $this->call('POST', '/admin/keys/rotate-emergency', $this->freshCookie($u), ['confirm' => 'rotate']);
        self::assertSame($active, $keys->findActive()?->kid, 'tanpa kata ROTATE tidak berjalan');
        self::assertSame(0, $this->auditCount('key.emergency_rotation'));
        $this->call('POST', '/admin/keys/rotate-emergency', $this->freshCookie($u), ['confirm' => 'ROTATE']);
        self::assertNotSame($active, $keys->findActive()?->kid);
        self::assertSame(1, $this->auditCount('key.emergency_rotation'));
        self::assertStringNotContainsString('BEGIN', $this->allAudit());
        self::assertSame(200, $this->call('GET', '/admin/keys', $this->freshCookie($u))->status);
        self::assertSame($res->status, 303);
    }

    private function freshCookie(User $u): string
    {
        return $this->s->manager->start($u->id, 'password', true, new Request('POST', '/login', [], [], [], [], [], '198.51.100.7'))->value;
    }

    // ---------- pemeliharaan ----------

    public function testMaintenanceRotateKeysHanyaTerjadwalDenganAudit(): void
    {
        [, $op] = $this->admin('operator');
        $keys = new SigningKeyRepository($this->s->db);
        $this->call('POST', '/admin/maintenance/rotate-keys', $op);
        self::assertSame([], $keys->listByStatus('next'), 'belum jatuh tempo: tidak ada rotasi paksa');
        $ctx = json_decode((string) $this->s->db->pdo()->query("SELECT context FROM audit_logs WHERE event = 'key.rotate_scheduled'")->fetchColumn(), true);
        self::assertFalse($ctx['after']['rotated']);

        $this->s->clock->advance('+89 days');
        $op = $this->admin('operator')[1];
        $this->call('POST', '/admin/maintenance/rotate-keys', $op);
        self::assertCount(1, $keys->listByStatus('next'));
        self::assertSame(2, $this->auditCount('key.rotate_scheduled'));
    }

    public function testMaintenanceRotateKeysWajibStepUp(): void
    {
        [, $op] = $this->admin('operator');
        $this->s->clock->advance('+6 minutes');
        self::assertSame('/admin/stepup', $this->call('POST', '/admin/maintenance/rotate-keys', $op)->headers['Location']);
        self::assertSame(0, $this->auditCount('key.rotate_scheduled'));
    }

    public function testGcBerjalanDanDiaudit(): void
    {
        [, $op] = $this->admin('operator');
        $res = $this->call('POST', '/admin/maintenance/gc', $op);
        self::assertSame(303, $res->status);
        self::assertStringContainsString('GC selesai', (string) json_encode($_SESSION['flash']));
        self::assertSame(1, $this->auditCount('maintenance.gc'));
    }

    public function testRetryLogoutMenghidupkanYangGagal(): void
    {
        [, $sa] = $this->admin('superadmin');
        $id = $this->createClient($sa);
        $cl = $this->c->get(ClientRepository::class)->findByClientId($id);
        $sid = '11111111-1111-4111-8111-111111111111';
        $this->s->db->pdo()->prepare('INSERT INTO logout_notifications (client_id, sid, user_uuid, attempts, next_try_at, last_error) VALUES (?, ?, ?, 5, ?, ?)')
            ->execute([$cl?->id, $sid, 'uuid-1', '2026-03-01 09:00:00', 'http_500']);
        self::assertStringContainsString('<strong>1</strong>', $this->call('GET', '/admin/maintenance', $sa)->body);
        $this->call('POST', '/admin/maintenance/retry-logout', $sa);
        self::assertCount(1, $this->sent);
        self::assertSame(1, $this->rows('logout_notifications', 'delivered_at IS NOT NULL'));
        self::assertSame(1, $this->auditCount('maintenance.retry_logout'));
    }

    // ---------- perbaikan T9 ----------

    public function testInstallLockDilepasBilaCreateGagalTakTerduga(): void
    {
        $s = $this->s;
        $s->db->pdo()->exec('DROP TABLE audit_logs'); // admin.bootstrap audit gagal setelah transaksi? pastikan gagal di create
        $s->db->pdo()->exec('DROP TABLE users');
        $ctl = new InstallController(
            $s->view,
            new InstallGuard($s->roles, $this->lock),
            new AdminCreator($s->db, $s->users, $s->roles, $s->hasher, $s->audit, $s->clock),
            $s->limiter,
        );
        try {
            $ctl->submit(new Request('POST', '/install', [], ['email' => 'root@example.test', 'password' => 'kata-sandi-panjang-1', 'password2' => 'kata-sandi-panjang-1'], [], [], [], '198.51.100.7'));
            self::fail('seharusnya melempar');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\App\Http\HttpException::class, $e);
        }
        self::assertFileDoesNotExist($this->lock, 'install.lock tak boleh tertinggal tanpa admin');
    }

    private function resetSent(): void
    {
        $this->sent = [];
    }

    private function sentCount(): int
    {
        return count($this->sent);
    }
}
