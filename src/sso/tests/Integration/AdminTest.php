<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Account\AdminCreator;
use App\Admin\AdminPolicy;
use App\Admin\InstallGuard;
use App\Admin\PendingActionStore;
use App\Controller\Admin\AdminPage;
use App\Controller\Admin\RolesController;
use App\Controller\InstallController;
use App\Http\HttpException;
use App\Http\Kernel;
use App\Http\Middleware\Csrf;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\RequireSsoSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repository\AdminRoleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\ClientRepository;
use App\Repository\LogoutNotificationRepository;
use App\Repository\Record\User;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Security\Crypto;
use App\Security\RateLimiter;
use App\Security\Totp;
use App\Session\SessionTerminator;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Container;
use App\Support\Csv;
use App\Support\Database;
use App\Support\Logger;
use App\Support\UiSession;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** SQLite in-memory (bukan MariaDB): menguji logika admin, bukan dialek SQL produksi. */
final class AdminTest extends TestCase
{
    private Stack $s;
    private Kernel $kernel;
    private string $lock;
    /** @var array<int,string> secret TOTP per user id */
    private array $secrets = [];

    protected function setUp(): void
    {
        UiSession::fake();
        $this->s = new Stack();
        $this->lock = sys_get_temp_dir() . '/bsso-install-' . bin2hex(random_bytes(4)) . '.lock';
        $c = new Container();
        $s = $this->s;
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
        $c->set(InstallGuard::class, new InstallGuard($s->roles, $this->lock));
        foreach (
            [
            UserRepository::class => $s->users, SsoSessionRepository::class => $s->sessions,
            AdminRoleRepository::class => $s->roles, AuditLogRepository::class => $s->auditLogs,
            ] as $id => $obj
        ) {
            $c->set($id, $obj);
        }
        foreach ([SettingRepository::class, ClientRepository::class, LogoutNotificationRepository::class, SigningKeyRepository::class] as $repo) {
            $c->set($repo, new $repo($s->db));
        }
        $router = new Router();
        require __DIR__ . '/../../routes.php';
        $this->kernel = new Kernel($c, $router, $s->logger);
        $c->set(Kernel::class, $this->kernel);
        $c->set(Router::class, $router);
    }

    protected function tearDown(): void
    {
        UiSession::reset();
        putenv('BSSO_INSTALL_TOKEN');
        @unlink($this->lock);
    }

    // ---------- helpers ----------

    /** @return array{User, string} user dan nilai cookie sesi */
    private function admin(?string $role, bool $mfa = true, ?string $email = null): array
    {
        $s = $this->s;
        $email ??= ($role ?? 'biasa') . bin2hex(random_bytes(2)) . '@example.test';
        $u = $s->users->create($email, null, ucfirst($role ?? 'biasa'));
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

    private function code(User $u, int $offset = 0): string
    {
        $step = intdiv($this->s->clock->now()->getTimestamp(), 30) + $offset;

        return $this->s->totp->code(Totp::base32Decode($this->secrets[$u->id]), $step);
    }

    /** @param array<string,mixed> $body */
    private function call(string $method, string $path, string $cookie, array $body = [], bool $csrf = true): Response
    {
        if ($method === 'POST' && $csrf) {
            $body['_csrf'] = Csrf::currentToken();
        }

        return $this->kernel->handle(new Request(
            $method,
            $path,
            [],
            $body,
            ['user-agent' => 'PHPUnit'],
            [SsoSessionManager::COOKIE => $cookie],
            [],
            '198.51.100.7',
        ));
    }

    /**
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function settingsBody(array $o = []): array
    {
        return $o + [
            'sso_session_hours' => '8', 'password_min' => '12', 'rate_limit_attempts' => '5', 'rate_limit_window_min' => '15',
            'key_rotation_days' => '90', 'key_overlap_days' => '2', 'admin_idle_minutes' => '15',
        ];
    }

    /** @phpstan-impure */
    private function roleOf(int $userId): ?string
    {
        $r = $this->s->roles->findByUserId($userId);

        return $r === null ? null : $r->role;
    }

    private function auditCount(string $event): int
    {
        $st = $this->s->db->pdo()->prepare('SELECT COUNT(*) FROM audit_logs WHERE event = ?');
        $st->execute([$event]);

        return (int) $st->fetchColumn();
    }

    private function settingValue(string $name): mixed
    {
        return (new SettingRepository($this->s->db))->get($name)?->value;
    }

    // ---------- kebijakan & urutan middleware ----------

    public function testMatriksSesuaiRequirementsDanTakDikenalNone(): void
    {
        $p = new AdminPolicy();
        self::assertSame('none', $p->level('hacker', 'dashboard'));
        self::assertSame('none', $p->level('superadmin', 'tak-ada'));
        self::assertSame('manage', $p->level('superadmin', 'roles'));
        self::assertSame('none', $p->level('operator', 'roles'));
        self::assertSame('none', $p->level('operator', 'clients'));
        self::assertSame('manage', $p->level('operator', 'maintenance'));
        self::assertSame('read', $p->level('auditor', 'settings'));
        self::assertSame('manage', $p->level('auditor', 'audit'));
        self::assertSame('read', $p->level('operator', 'audit'));
        self::assertFalse($p->canAssign('operator', 'auditor'));
        self::assertFalse($p->canAssign('auditor', 'auditor'));
        self::assertTrue($p->canAssign('superadmin', 'operator', 'auditor'));
        self::assertFalse($p->canManageUser('operator', 'auditor'));
        self::assertTrue($p->canManageUser('operator', null));
    }

    public function testRequireAdminSelaluSebelumRequireRecentAuthDiSemuaRute(): void
    {
        $router = new Router();
        require __DIR__ . '/../../routes.php';
        $routes = (new \ReflectionProperty($router, 'routes'))->getValue($router);
        $checked = 0;
        foreach ($routes as $route) {
            $names = array_map(static fn (mixed $m): string => is_array($m) ? (string) $m[0] : (string) $m, $route['middleware']);
            $recent = array_search(RequireRecentAuth::class, $names, true);
            if ($recent === false) {
                continue;
            }
            $admin = array_search(RequireAdmin::class, $names, true);
            $sso = array_search(RequireSsoSession::class, $names, true);
            self::assertIsInt($admin, $route['regex']);
            self::assertLessThan($recent, $admin, $route['regex']);
            self::assertLessThan($admin, $sso, $route['regex']);
            self::assertSame('POST', $route['method'], 'step-up hanya untuk POST');
            $checked++;
        }
        self::assertGreaterThan(10, $checked);
    }

    public function testRequireRecentAuthTanpaRequireAdminMelempar(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        $session = $this->s->sessions->findByIdHash(hash('sha256', $cookie));
        $mw = new RequireRecentAuth($this->s->clock, new PendingActionStore($this->s->crypto, $this->s->clock));
        $this->expectException(\LogicException::class);
        $mw->process((new Request('POST', '/admin/settings'))->withAttribute('sso_session', $session), static fn (Request $r): Response => new Response());
    }

    // ---------- RequireAdmin ----------

    public function testTanpaSesiDialihkanKeLoginDanBukanAdmin403(): void
    {
        $anon = $this->kernel->handle(new Request('GET', '/admin'));
        self::assertSame(302, $anon->status);
        self::assertStringStartsWith('/login', $anon->headers['Location']);
        [, $cookie] = $this->admin(null);
        self::assertSame(403, $this->call('GET', '/admin', $cookie)->status);
    }

    public function testAdminTanpaMfaDialihkanKeAccountMfa(): void
    {
        [, $cookie] = $this->admin('superadmin', false);
        $res = $this->call('GET', '/admin', $cookie);
        self::assertSame(302, $res->status);
        self::assertSame('/account/mfa', $res->headers['Location']);
    }

    public function testLevelKurangDari403DanOperatorTakBisaAksiManage(): void
    {
        [, $op] = $this->admin('operator');
        self::assertSame(403, $this->call('GET', '/admin/settings', $op)->status);
        self::assertSame(403, $this->call('POST', '/admin/settings', $op, $this->settingsBody())->status);
        self::assertSame(403, $this->call('GET', '/admin/roles', $op)->status);
        self::assertSame(403, $this->call('POST', '/admin/roles', $op, ['id' => '1', 'role' => 'superadmin'])->status);
        self::assertSame(403, $this->call('POST', '/admin/roles/1/revoke', $op)->status);
        self::assertSame(403, $this->call('GET', '/admin/audit/export', $op)->status, 'operator: audit hanya baca');
        self::assertSame(200, $this->call('GET', '/admin/audit', $op)->status);
        self::assertGreaterThan(0, $this->auditCount('admin.denied'));
        self::assertNull($this->settingValue('key.rotation_days'));
    }

    public function testAuditorBacaSajaDiPengaturan(): void
    {
        [, $au] = $this->admin('auditor');
        $res = $this->call('GET', '/admin/settings', $au);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('hanya dapat membaca', $res->body);
        self::assertSame(403, $this->call('POST', '/admin/settings', $au, $this->settingsBody())->status);
    }

    public function testIdle15MenitMemaksaLoginUlangDanMencabutSesi(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        self::assertSame(200, $this->call('GET', '/admin', $cookie)->status);
        $this->s->clock->advance('+16 minutes');
        $res = $this->call('GET', '/admin', $cookie);
        self::assertSame(302, $res->status);
        self::assertStringStartsWith('/login', $res->headers['Location']);
        $sess = $this->s->sessions->findByIdHash(hash('sha256', $cookie));
        self::assertNotNull($sess?->revokedAt);
        self::assertSame(1, $this->auditCount('admin.idle_logout'));
        self::assertSame(302, $this->call('GET', '/admin', $cookie)->status);
    }

    public function testAksesAccountTidakMereseIdleAdmin(): void
    {
        [, $cookie] = $this->admin('superadmin');
        self::assertSame(200, $this->call('GET', '/admin', $cookie)->status);
        $this->s->clock->advance('+10 minutes');
        $this->call('GET', '/account', $cookie);
        $this->s->clock->advance('+10 minutes');
        $res = $this->call('GET', '/admin', $cookie);
        self::assertSame(302, $res->status);
        self::assertStringStartsWith('/login', $res->headers['Location']);
        self::assertSame(1, $this->auditCount('admin.idle_logout'));
    }

    public function testStepUpTidakMenghidupkanKembaliSesiAdminYangIdle(): void
    {
        [, $cookie] = $this->admin('superadmin');
        $this->call('GET', '/admin', $cookie);
        $this->s->clock->advance('+16 minutes');
        $res = $this->call('GET', '/admin/stepup', $cookie);
        self::assertSame(303, $res->status);
        self::assertSame('/login', $res->headers['Location']);
        self::assertNotNull($this->s->sessions->findByIdHash(hash('sha256', $cookie))?->revokedAt);
    }

    public function testIdleMengikutiPengaturanAdminIdleMinutes(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        (new SettingRepository($this->s->db))->set('admin.idle_minutes', 30, $u->id, $this->s->clock->now());
        $this->s->clock->advance('+20 minutes');
        self::assertSame(200, $this->call('GET', '/admin', $cookie)->status);
    }

    public function testSesiTanpaMfaPassedDialihkanKeStepUp(): void
    {
        [$u] = $this->admin('superadmin');
        $cookie = $this->s->manager->start($u->id, 'password', false, new Request('POST', '/login'))->value;
        $res = $this->call('GET', '/admin', $cookie);
        self::assertSame('/admin/stepup', $res->headers['Location']);
    }

    // ---------- dasbor ----------

    public function testDasborMenampilkanMetrik(): void
    {
        [, $cookie] = $this->admin('auditor');
        $this->s->audit->record('login.success', 1);
        $this->s->audit->record('login.success', 1);
        $this->s->audit->record('login.failure', null);
        $this->s->audit->record('token.reuse_detected', 1);
        $res = $this->call('GET', '/admin', $cookie);
        self::assertSame(200, $res->status);
        self::assertMatchesRegularExpression('#stat-n">2</span><span>Login berhasil#', $res->body);
        self::assertMatchesRegularExpression('#stat-n">1</span><span>Login gagal#', $res->body);
        self::assertMatchesRegularExpression('#stat-n">1</span><span>Sesi aktif#', $res->body);
    }

    // ---------- step-up & aksi tertunda ----------

    public function testStepUpMenundaLaluMenjalankanTepatSekaliDenganCsrfBaru(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        $this->s->clock->advance('+6 minutes');
        $oldCsrf = Csrf::currentToken();

        $res = $this->call('POST', '/admin/settings', $cookie, $this->settingsBody(['key_rotation_days' => '60']));
        self::assertSame(303, $res->status);
        self::assertSame('/admin/stepup', $res->headers['Location']);
        self::assertNull($this->settingValue('key.rotation_days'), 'belum dijalankan');

        $raw = (string) ($_SESSION['stepup_pending'] ?? '');
        self::assertNotSame('', $raw);
        self::assertStringNotContainsString('key_rotation_days', $raw, 'aksi tertunda terenkripsi');
        self::assertStringContainsString('settings', $this->call('GET', '/admin/stepup', $cookie)->body);

        $bad = $this->call('POST', '/admin/stepup', $cookie, ['code' => '000000']);
        self::assertSame(401, $bad->status);
        self::assertNull($this->settingValue('key.rotation_days'));

        $ok = $this->call('POST', '/admin/stepup', $cookie, ['code' => $this->code($u)]);
        self::assertSame(303, $ok->status);
        self::assertSame('/admin/settings', $ok->headers['Location']);
        self::assertSame(60, $this->settingValue('key.rotation_days'));
        self::assertNotSame($oldCsrf, Csrf::currentToken(), 'CSRF dirotasi saat replay');
        self::assertNotEmpty($ok->cookies);
        $newCookie = $ok->cookies[0]->value;
        self::assertNotSame($cookie, $newCookie);
        self::assertSame(302, $this->call('GET', '/admin', $cookie)->status, 'cookie lama tak berlaku');
        self::assertSame(200, $this->call('GET', '/admin', $newCookie)->status);

        // Replay kedua: tak ada aksi tertunda lagi.
        $this->s->clock->advance('+31 seconds');
        $again = $this->call('POST', '/admin/stepup', $newCookie, ['code' => $this->code($u)]);
        self::assertSame('/admin', $again->headers['Location']);
        self::assertSame(1, $this->auditCount('settings.update'));
    }

    public function testAuditBeforeAfterTanpaRahasia(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        $res = $this->call('POST', '/admin/settings', $cookie, $this->settingsBody(['key_rotation_days' => '45', 'admin_idle_minutes' => '20']));
        self::assertSame('/admin/settings', $res->headers['Location']);
        $row = $this->s->db->pdo()->query("SELECT context FROM audit_logs WHERE event = 'settings.update'")->fetchColumn();
        $ctx = json_decode((string) $row, true);
        self::assertSame(['before' => 90, 'after' => 45], $ctx['changes']['key.rotation_days']);
        self::assertSame(['before' => 15, 'after' => 20], $ctx['changes']['admin.idle_minutes']);
        self::assertCount(2, $ctx['changes'], 'hanya nilai yang berubah');
    }

    public function testStepUpKedaluwarsaTidakMenjalankanAksi(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        $this->s->clock->advance('+6 minutes');
        $this->call('POST', '/admin/settings', $cookie, $this->settingsBody(['admin_idle_minutes' => '30']));
        $this->s->clock->advance('+6 minutes');
        $res = $this->call('POST', '/admin/stepup', $cookie, ['code' => $this->code($u)]);
        self::assertSame('/admin', $res->headers['Location']);
        self::assertNull($this->settingValue('admin.idle_minutes'));
    }

    public function testAksiTertundaTidakBisaDipakaiSesiAtauUserLain(): void
    {
        [$a, $cookieA] = $this->admin('superadmin');
        [$b, $cookieB] = $this->admin('superadmin');
        $this->s->clock->advance('+6 minutes');
        $this->call('POST', '/admin/settings', $cookieA, $this->settingsBody(['admin_idle_minutes' => '30']));
        $res = $this->call('POST', '/admin/stepup', $cookieB, ['code' => $this->code($b)]);
        self::assertSame('/admin', $res->headers['Location'], 'B tidak menjalankan aksi A');
        self::assertNull($this->settingValue('admin.idle_minutes'));
        self::assertNull($_SESSION['stepup_pending'] ?? null, 'tidak tersisa untuk dipakai ulang');
    }

    public function testReplayHanyaRuteDenganRequireRecentAuth(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        $session = $this->s->sessions->findByIdHash(hash('sha256', $cookie));
        self::assertNotNull($session);
        $store = new PendingActionStore($this->s->crypto, $this->s->clock);
        // toggle klien tidak memakai step-up (aksi defensif) -> tak boleh dijalankan lewat replay
        self::assertTrue($store->save($session, 'POST', '/admin/clients/1/toggle', ['x' => '1']));
        $res = $this->call('POST', '/admin/stepup', $cookie, ['code' => $this->code($u)]);
        self::assertSame(303, $res->status);
        self::assertSame('/admin', $res->headers['Location']);
    }

    public function testPathAksiTertundaDiwhitelist(): void
    {
        self::assertTrue(PendingActionStore::pathAllowed('POST', '/admin/settings'));
        self::assertFalse(PendingActionStore::pathAllowed('GET', '/admin/settings'));
        self::assertFalse(PendingActionStore::pathAllowed('POST', '/admin/stepup'));
        self::assertFalse(PendingActionStore::pathAllowed('POST', '/login'));
        self::assertFalse(PendingActionStore::pathAllowed('POST', '/admin/../token'));
        self::assertFalse(PendingActionStore::pathAllowed('POST', '/admin//x'));
        self::assertFalse(PendingActionStore::pathAllowed('POST', 'https://evil.test/admin/x'));
        self::assertFalse(PendingActionStore::pathAllowed('POST', "/admin/x\r\nHost: evil"));
    }

    public function testPostAdminTanpaCsrf419(): void
    {
        [, $cookie] = $this->admin('superadmin');
        self::assertSame(419, $this->call('POST', '/admin/settings', $cookie, $this->settingsBody(), false)->status);
        self::assertSame(419, $this->call('POST', '/admin/roles', $cookie, ['id' => '9', 'role' => 'auditor'], false)->status);
        self::assertSame(419, $this->call('POST', '/admin/stepup', $cookie, ['code' => '123456'], false)->status);
    }

    public function testStepUpTerbatasPercobaanMfa(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->call('POST', '/admin/stepup', $cookie, ['code' => '000000'])->status);
        }
        self::assertSame(429, $this->call('POST', '/admin/stepup', $cookie, ['code' => $this->code($u)])->status);
    }

    public function testStepUpTotpDipakaiUlangDitolak(): void
    {
        [$u, $cookie] = $this->admin('superadmin');
        $code = $this->code($u);
        $first = $this->call('POST', '/admin/stepup', $cookie, ['code' => $code]);
        self::assertSame(303, $first->status);
        $res = $this->call('POST', '/admin/stepup', $first->cookies[0]->value, ['code' => $code]);
        self::assertSame(401, $res->status, 'langkah TOTP sama tidak bisa dipakai dua kali');
    }

    // ---------- role ----------

    public function testBerirolePerubahanDanAuditBeforeAfter(): void
    {
        [$a, $cookie] = $this->admin('superadmin');
        [$t] = $this->admin(null);
        $res = $this->call('POST', '/admin/roles', $cookie, ['id' => (string) $t->id, 'role' => 'operator']);
        self::assertSame('/admin/roles', $res->headers['Location']);
        self::assertSame('operator', $this->roleOf($t->id));
        $this->call('POST', '/admin/roles', $cookie, ['id' => (string) $t->id, 'role' => 'auditor']);
        self::assertSame('auditor', $this->roleOf($t->id));
        $ctx = json_decode((string) $this->s->db->pdo()->query("SELECT context FROM audit_logs WHERE event = 'role.change'")->fetchColumn(), true);
        self::assertSame(['role' => 'operator'], $ctx['before']);
        self::assertSame(['role' => 'auditor'], $ctx['after']);
        self::assertStringContainsString($t->email ?? '', $this->call('GET', '/admin/roles', $cookie)->body);
    }

    public function testAdminTidakBisaMengubahAtauMencabutRoleSendiri(): void
    {
        [$a, $cookie] = $this->admin('superadmin');
        $this->admin('superadmin');
        $this->call('POST', '/admin/roles', $cookie, ['id' => (string) $a->id, 'role' => 'auditor']);
        self::assertSame('superadmin', $this->roleOf($a->id));
        $this->call('POST', '/admin/roles/' . $a->id . '/revoke', $cookie);
        self::assertSame('superadmin', $this->roleOf($a->id));
    }

    public function testSuperadminTerakhirTidakBisaDihapusAtauDiturunkan(): void
    {
        $s = $this->s;
        [$last] = $this->admin('superadmin');
        $other = $s->users->create('stale@example.test', null, 'Stale');
        $c = new RolesController(
            new AdminPage($s->view, new AdminPolicy()),
            $s->roles,
            $s->users,
            $s->db,
            $s->audit,
            $s->clock,
        );
        // Aktor basi (role di request masih superadmin padahal di DB sudah tidak) menyasar superadmin terakhir.
        $mk = static fn (string $path, array $body, array $attr) => array_reduce(
            array_keys($attr),
            static fn (Request $r, string $k): Request => $r->withAttribute($k, $attr[$k]),
            new Request('POST', $path, [], $body),
        );
        $common = ['user' => $other, 'admin_role' => 'superadmin'];
        $c->revoke($mk('/admin/roles/x/revoke', [], $common + ['id' => (string) $last->id]));
        self::assertSame('superadmin', $this->roleOf($last->id));
        $c->grant($mk('/admin/roles', ['id' => (string) $last->id, 'role' => 'auditor'], $common));
        self::assertSame('superadmin', $this->roleOf($last->id));
        self::assertSame(0, $this->auditCount('role.revoke') + $this->auditCount('role.change'));
    }

    public function testEskalasiHakDicegah(): void
    {
        $s = $this->s;
        $op = $s->users->create('op@example.test', null, 'Op');
        $t = $s->users->create('t@example.test', null, 'T');
        $c = new RolesController(new AdminPage($s->view, new AdminPolicy()), $s->roles, $s->users, $s->db, $s->audit, $s->clock);
        $req = (new Request('POST', '/admin/roles', [], ['id' => (string) $t->id, 'role' => 'superadmin']))
            ->withAttribute('user', $op)->withAttribute('admin_role', 'operator');
        $c->grant($req);
        self::assertNull($s->roles->findByUserId($t->id));
        self::assertSame(1, $this->auditCount('role.escalation_blocked'));
    }

    public function testRoleIdorDanInputTidakValid(): void
    {
        [, $cookie] = $this->admin('superadmin');
        [$plain] = $this->admin(null);
        $this->call('POST', '/admin/roles', $cookie, ['id' => '99999', 'role' => 'operator']);
        $this->call('POST', '/admin/roles', $cookie, ['id' => (string) $plain->id, 'role' => 'dewa']);
        $this->call('POST', '/admin/roles', $cookie, ['id' => '1 OR 1=1', 'role' => 'operator']);
        self::assertNull($this->s->roles->findByUserId($plain->id));
        self::assertCount(1, $this->s->roles->listAll());
        $this->call('POST', '/admin/roles/' . $plain->id . '/revoke', $cookie);
        self::assertSame(404, $this->call('POST', '/admin/roles/abc/revoke', $cookie)->status);
        $res = $this->call('POST', '/admin/roles', $cookie, ['id' => (string) $plain->id, 'role' => 'auditor', 'back' => 'https://evil.test/']);
        self::assertSame('/admin/roles', $res->headers['Location'], 'back eksternal diabaikan');
    }

    // ---------- pengaturan ----------

    public function testPengaturanValidasiMenolakNilaiLiar(): void
    {
        [, $cookie] = $this->admin('superadmin');
        self::assertSame(422, $this->call('POST', '/admin/settings', $cookie, $this->settingsBody(['password_min' => '4']))->status);
        self::assertSame(422, $this->call('POST', '/admin/settings', $cookie, $this->settingsBody(['key_rotation_days' => 'abc']))->status);
        self::assertSame(422, $this->call('POST', '/admin/settings', $cookie, $this->settingsBody(['key_overlap_days' => '90', 'key_rotation_days' => '30']))->status);
        self::assertSame(0, $this->auditCount('settings.update'));
        self::assertNull($this->settingValue('auth.min_length'));
    }

    // ---------- audit log & CSV ----------

    public function testFilterAuditDanPaginasi(): void
    {
        [, $cookie] = $this->admin('auditor');
        for ($i = 0; $i < 30; $i++) {
            $this->s->audit->record('login.success', null);
        }
        $this->s->audit->record('x_y%z', null);
        $res = $this->call('GET', '/admin/audit', $cookie);
        self::assertStringContainsString('halaman 1 dari 2', $res->body);
        $filtered = $this->s->auditLogs->listFiltered(['event' => '%'], 10, 0);
        self::assertSame([], $filtered, 'wildcard LIKE di-escape');
        self::assertCount(1, $this->s->auditLogs->listFiltered(['event' => 'x_y'], 10, 0));
        self::assertSame(30, $this->s->auditLogs->countFiltered(['event' => 'login.']));
    }

    public function testEksporCsvTahanFormulaInjectionDanTanpaRahasia(): void
    {
        $s = $this->s;
        [, $cookie] = $this->admin('auditor');
        $evil = $s->users->create('=cmd@example.test', null, '+Evil');
        $s->audit->record('=HYPERLINK("http://x")', $evil->id, $evil->id, null, ['password' => 'RAHASIA1', 'note' => '@SUM(1)']);
        $s->auditLogs->insert('@evil', $evil->id, null, null, '203.0.113.1', ['client_secret' => 'RAHASIA2', 'ok' => '-1'], $s->clock->now());

        $res = $this->call('GET', '/admin/audit/export', $cookie);
        self::assertSame(200, $res->status);
        self::assertStringStartsWith('text/csv', $res->headers['Content-Type']);
        self::assertStringContainsString('attachment', $res->headers['Content-Disposition']);
        self::assertStringNotContainsString('RAHASIA', $res->body);
        $h = fopen('php://temp', 'r+');
        fwrite($h, $res->body);
        rewind($h);
        $cells = 0;
        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
            foreach ($row as $cell) {
                self::assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, 'sel: ' . $cell);
                $cells++;
            }
        }
        self::assertGreaterThan(10, $cells);
        self::assertStringContainsString("'=HYPERLINK", $res->body);
        self::assertStringContainsString("'=cmd@example.test", $res->body);
        self::assertSame(1, $this->auditCount('audit.export'));
    }

    public function testCsvCell(): void
    {
        foreach (['=1+1', '+1', '-1', '@a', "\tx", "\rx"] as $v) {
            self::assertSame("'" . $v, Csv::cell($v));
        }
        self::assertSame('aman', Csv::cell('aman'));
        self::assertSame('12', Csv::cell(12));
        self::assertSame('', Csv::cell(null));
    }

    // ---------- /install ----------

    private function installer(): InstallController
    {
        $s = $this->s;

        return new InstallController(
            $s->view,
            new InstallGuard($s->roles, $this->lock),
            new AdminCreator($s->db, $s->users, $s->roles, $s->hasher, $s->audit, $s->clock),
            $s->limiter,
        );
    }

    /** @return array<string,string> */
    private function installBody(string $pw = 'kata-sandi-panjang-1'): array
    {
        return ['email' => 'root@example.test', 'password' => $pw, 'password2' => $pw];
    }

    public function testInstallMembuatSuperadminLaluTerkunci(): void
    {
        putenv('BSSO_INSTALL_TOKEN=rahasia-token');
        $c = $this->installer();
        self::assertSame(200, $c->form(new Request('GET', '/install'))->status);
        $res = $c->submit(new Request('POST', '/install', [], $this->installBody() + ['install_token' => 'rahasia-token'], [], [], [], '198.51.100.7'));
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Pemasangan selesai', $res->body);
        self::assertFileExists($this->lock);
        self::assertCount(1, $this->s->roles->listAll());
        foreach ([fn () => $c->form(new Request('GET', '/install')), fn () => $c->submit(new Request('POST', '/install', [], ['email' => 'x@example.test'] + $this->installBody()))] as $f) {
            try {
                $f();
                self::fail('harus 404');
            } catch (HttpException $e) {
                self::assertSame(404, $e->status);
            }
        }
        self::assertCount(1, $this->s->roles->listAll());
        self::assertSame(404, $this->kernel->handle(new Request('GET', '/install'))->status);
    }

    public function testInstallTerkunciBilaFlagAdaAtauAdminAda(): void
    {
        $guard = new InstallGuard($this->s->roles, $this->lock);
        self::assertTrue($guard->open());
        file_put_contents($this->lock, 'x');
        self::assertFalse($guard->open());
        self::assertFalse($guard->acquire());
        unlink($this->lock);
        $this->s->roles->create($this->s->users->create('a@example.test', null, 'A')->id, 'superadmin', null, $this->s->clock->now());
        self::assertFalse($guard->open());
    }

    public function testInstallRaceHanyaSatuPemenang(): void
    {
        $g1 = new InstallGuard($this->s->roles, $this->lock);
        $g2 = new InstallGuard($this->s->roles, $this->lock);
        self::assertTrue($g1->acquire());
        self::assertFalse($g2->acquire());
        $g1->release();
        self::assertTrue($g2->acquire());
    }

    public function testInstallTanpaTokenDanTokenSalahSeragam404(): void
    {
        $c = $this->installer();
        $req = static fn (string $m, array $b = [], array $h = []): Request => new Request($m, '/install', [], $b, $h, [], [], '198.51.100.7');
        $attempts = [
            fn () => $c->form($req('GET')),
            fn () => $c->submit($req('POST', $this->installBody() + ['install_token' => ''])),
            fn () => $c->submit($req('POST', $this->installBody())),
        ];
        putenv('BSSO_INSTALL_TOKEN=rahasia-token');
        $attempts[] = fn () => $c->submit($req('POST', $this->installBody() + ['install_token' => 'salah']));
        $attempts[] = fn () => $c->submit($req('POST', $this->installBody()));
        foreach ($attempts as $i => $f) {
            if ($i < 3) {
                putenv('BSSO_INSTALL_TOKEN');
            } else {
                putenv('BSSO_INSTALL_TOKEN=rahasia-token');
            }
            try {
                $f();
                self::fail('harus 404');
            } catch (HttpException $e) {
                self::assertSame(404, $e->status);
            }
        }
        self::assertFileDoesNotExist($this->lock);
        self::assertCount(0, $this->s->roles->listAll());
        // token benar lewat header diterima
        $res = $c->submit($req('POST', $this->installBody(), ['x-install-token' => 'rahasia-token']));
        self::assertSame(200, $res->status);
    }

    public function testInstallGagalValidasiTidakMengunciDanTokenWajib(): void
    {
        putenv('BSSO_INSTALL_TOKEN=rahasia-token');
        $c = $this->installer();
        $req = static fn (array $b): Request => new Request('POST', '/install', [], $b + ['install_token' => 'rahasia-token'], [], [], [], '198.51.100.7');
        self::assertSame(422, $c->submit($req($this->installBody('pendek')))->status);
        self::assertFileDoesNotExist($this->lock);
        self::assertSame(422, $c->submit($req(['password2' => 'beda'] + $this->installBody()))->status);
        self::assertSame(200, $c->submit($req($this->installBody()))->status);
        self::assertFileExists($this->lock);
    }
}
