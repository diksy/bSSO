<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\Account\AccountPage;
use App\Controller\Account\SessionsController;
use App\Controller\LogoutController;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\OAuth\TokenIssuer;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\LogoutNotificationRepository;
use App\Repository\Record\Client;
use App\Repository\Record\RefreshToken;
use App\Repository\Record\SsoSession;
use App\Repository\Record\User;
use App\Repository\RefreshTokenRepository;
use App\Repository\SessionClientRepository;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Security\KeyManager;
use App\Session\BackchannelNotifier;
use App\Session\LogoutService;
use App\Session\SsoSessionManager;
use App\Support\CronRunner;
use App\Support\Gc;
use App\Support\HttpClient;
use App\Support\HttpResult;
use App\Support\Logger;
use App\Support\UiSession;
use App\Support\UrlAllowlist;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** SQLite in-memory + HTTP palsu (BUKAN MariaDB, tanpa jaringan nyata): logika logout, back-channel, retry, sesi aktif. */
final class LogoutTest extends TestCase
{
    private const BYE = 'https://app.example.test/bye';
    private const DNS = [
        'a.example.test' => ['93.184.216.34'],
        'b.example.test' => ['93.184.216.35'],
        'app.example.test' => ['93.184.216.36'],
    ];

    private Stack $s;
    private string $keyDir;
    private KeyManager $km;
    private TokenIssuer $issuer;
    private ClientRepository $clients;
    private ClientRedirectUriRepository $uris;
    private SessionClientRepository $sessionClients;
    private RefreshTokenRepository $rt;
    private LogoutNotificationRepository $notes;
    private BackchannelNotifier $notifier;
    private LogoutService $logout;
    private LogoutController $controller;
    private SessionsController $sessionsCtl;
    private User $user;
    private Client $appA;
    private Client $appB;
    private Client $appC;
    /** @var list<array<string,mixed>> */
    private array $sent = [];
    /** @var list<int|string> respons berurutan (status atau kode galat); default 204 */
    private array $plan = [];
    /** @var list<callable> */
    private array $deferred = [];

    protected function setUp(): void
    {
        UiSession::fake();
        $s = $this->s = new Stack();
        $this->keyDir = sys_get_temp_dir() . '/bsso-logout-' . bin2hex(random_bytes(6));
        $this->km = new KeyManager(new SigningKeyRepository($s->db), new SettingRepository($s->db), $s->db, $s->clock, $s->audit, $this->keyDir);
        $this->km->rotateIfDue();
        $this->issuer = new TokenIssuer($this->km, $s->urls, $s->clock);
        $this->clients = new ClientRepository($s->db);
        $this->uris = new ClientRedirectUriRepository($s->db);
        $this->sessionClients = new SessionClientRepository($s->db);
        $this->rt = new RefreshTokenRepository($s->db);
        $this->notes = new LogoutNotificationRepository($s->db);

        $http = new HttpClient(
            new UrlAllowlist(static fn (string $h): array => self::DNS[$h] ?? []),
            function (array $reqs): array {
                $out = [];
                foreach ($reqs as $key => $r) {
                    $this->sent[] = $r;
                    $next = array_shift($this->plan) ?? 204;
                    $out[$key] = is_int($next) ? new HttpResult($next, '', $next >= 300 && $next < 400 ? ['location' => 'https://127.0.0.1/internal'] : []) : new HttpResult(0, '', [], $next);
                }

                return $out;
            },
        );
        $this->notifier = new BackchannelNotifier($this->notes, $this->sessionClients, $this->clients, $s->users, $this->issuer, $http, $s->clock, $s->audit);
        $this->logout = new LogoutService(
            $s->sessions,
            $this->rt,
            $this->notifier,
            $s->db,
            $s->clock,
            $s->audit,
            new Logger(sys_get_temp_dir() . '/bsso-test-logs'),
            function (callable $fn): void {
                $this->deferred[] = $fn;
            },
        );
        $this->controller = new LogoutController(
            $s->manager,
            $s->sessions,
            $this->sessionClients,
            $s->users,
            $this->clients,
            $this->uris,
            $this->km,
            $this->issuer,
            $this->logout,
            $s->audit,
            $s->view,
        );
        $this->sessionsCtl = new SessionsController(new AccountPage($s->view, $s->roles), $s->sessions, $this->logout, $s->manager, $s->audit, $s->clock);

        $now = $s->clock->now();
        $this->appA = $this->clients->create('cl_a', null, 'A', 'public', ['authorization_code'], 'openid', 900, 3600, 'https://a.example.test/bcl', $now);
        $this->appB = $this->clients->create('cl_b', null, 'B', 'public', ['authorization_code'], 'openid', 900, 3600, 'https://b.example.test/bcl', $now);
        $this->appC = $this->clients->create('cl_c', null, 'C', 'public', ['authorization_code'], 'openid', 900, 3600, null, $now);
        $this->uris->add($this->appA->id, self::BYE, 'post_logout');
        $this->user = $s->users->create('ani@example.test', null, 'Ani');
        $s->users->markEmailVerified($this->user->id, $now);
        $this->user = $s->users->findById($this->user->id) ?? $this->user;
    }

    protected function tearDown(): void
    {
        UiSession::reset();
        foreach (glob($this->keyDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->keyDir)) {
            rmdir($this->keyDir);
        }
    }

    // ---- helper ----

    /** @return array{0: SsoSession, 1: array<string,string>} sesi + cookie, dengan klien A, B, C tercatat */
    private function login(?User $user = null): array
    {
        $user ??= $this->user;
        $cookie = $this->s->manager->start($user->id, 'password', false, $this->req('GET', '/'));
        $session = $this->s->manager->current($this->req('GET', '/', [], [], [SsoSessionManager::COOKIE => $cookie->value]));
        self::assertNotNull($session);
        foreach ([$this->appA, $this->appB, $this->appC] as $c) {
            $this->sessionClients->add($session->sid, $c->id, $this->s->clock->now());
        }

        return [$session, [SsoSessionManager::COOKIE => $cookie->value]];
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $cookies
     */
    private function req(string $method, string $path, array $query = [], array $body = [], array $cookies = []): Request
    {
        return new Request($method, $path, $query, $body, ['user-agent' => 'Mozilla/5.0 (X11; Linux) Firefox/130'], $cookies, [], '198.51.100.7');
    }

    private function hint(SsoSession $s, Client $c): string
    {
        return $this->issuer->idToken($this->user, $c, $s, 'openid', null);
    }

    private function refreshToken(SsoSession $s, Client $c, string $hash): void
    {
        $now = $this->s->clock->now();
        $this->rt->insert(new RefreshToken($hash, 'fam-' . $hash, null, $c->id, $this->user->id, $s->sid, 'openid', $now, $now->modify('+1 day'), null, null));
    }

    /** @return array<string,mixed> */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $h = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        $p = json_decode((string) JWT::urlsafeB64Decode($parts[1]), true);
        self::assertIsArray($h);
        self::assertIsArray($p);

        return ['h' => $h, 'p' => $p];
    }

    private function active(string $sid): bool
    {
        $s = $this->s->sessions->findBySid($sid);

        return $s !== null && $s->revokedAt === null;
    }

    // ---- LogoutService + back-channel ----

    public function testEndSessionRevokesTokensQueuesAndSendsValidLogoutTokens(): void
    {
        [$session] = $this->login();
        $this->refreshToken($session, $this->appA, 'h1');
        $this->logout->endSession($session->sid, 'test');

        self::assertFalse($this->active($session->sid));
        self::assertNotNull($this->rt->findByHash('h1')?->revokedAt);
        self::assertCount(2, $this->sent, 'hanya A dan B (C tanpa backchannel_logout_uri)');
        self::assertCount(2, $this->notes->listBySid($session->sid));
        foreach ($this->notes->listBySid($session->sid) as $n) {
            self::assertNotNull($n->deliveredAt);
        }
        $urls = array_column($this->sent, 'url');
        sort($urls);
        self::assertSame(['https://a.example.test/bcl', 'https://b.example.test/bcl'], $urls);
        $ips = array_column($this->sent, 'ip');
        sort($ips);
        self::assertSame(['93.184.216.34', '93.184.216.35'], $ips, 'koneksi disematkan ke IP hasil resolusi');

        foreach ($this->sent as $req) {
            self::assertSame('POST', $req['method']);
            self::assertSame('application/x-www-form-urlencoded', $req['headers']['Content-Type']);
            parse_str((string) $req['body'], $form);
            self::assertSame(['logout_token'], array_keys($form));
            $c = self::claims((string) $form['logout_token']);
            self::assertSame('RS256', $c['h']['alg']);
            self::assertSame('logout+jwt', $c['h']['typ']);
            self::assertNotEmpty($c['h']['kid']);
            self::assertArrayNotHasKey('nonce', $c['p']);
            self::assertNotEmpty($c['p']['jti']);
            self::assertSame($session->sid, $c['p']['sid']);
            self::assertSame($this->user->uuid, $c['p']['sub']);
            self::assertSame(120, $c['p']['exp'] - $c['p']['iat']);
            self::assertEquals(new \stdClass(), (object) $c['p']['events']['http://schemas.openid.net/event/backchannel-logout']);
            // Tanda tangan valid (kunci kita) dan aud = client_id.
            $verified = $this->km->verify((string) $form['logout_token']);
            self::assertContains($verified['aud'], ['cl_a', 'cl_b']);
        }
        // Jti unik antar token.
        self::assertNotSame($this->sent[0]['body'], $this->sent[1]['body']);
    }

    public function testEndSessionIsIdempotentNoDoubleNotifications(): void
    {
        [$session] = $this->login();
        $this->logout->endSession($session->sid, 'a');
        $this->logout->endSession($session->sid, 'b');
        self::assertCount(2, $this->sent);
        self::assertCount(2, $this->notes->listBySid($session->sid));
        $this->logout->endSession('00000000-0000-4000-8000-000000000000', 'tak ada');
    }

    public function testRetryBackoffAndPermanentFailure(): void
    {
        [$session] = $this->login();
        $this->plan = [500, 204]; // A gagal, B sukses
        $this->logout->endSession($session->sid, 'test');
        $failed = array_values(array_filter($this->notes->listBySid($session->sid), static fn ($n): bool => $n->deliveredAt === null));
        self::assertCount(1, $failed);
        $id = $failed[0]->id;
        self::assertSame(1, $failed[0]->attempts);
        self::assertSame('http_500', $failed[0]->lastError);
        self::assertSame('2026-03-01 10:01:00', $failed[0]->nextTryAt->format('Y-m-d H:i:s'));

        $this->sent = [];
        self::assertSame(0, $this->notifier->dispatchPending(), 'belum waktunya');
        self::assertSame([], $this->sent);

        foreach ([[1, 5], [2, 15], [3, 60]] as [$attemptsBefore, $minutes]) {
            $this->s->clock->advance('+2 hours');
            $this->plan = [503];
            $this->notifier->dispatchPending();
            $n = $this->notes->findById($id);
            self::assertNotNull($n);
            self::assertSame($attemptsBefore + 1, $n->attempts);
            self::assertSame($minutes * 60, $n->nextTryAt->getTimestamp() - $this->s->clock->now()->getTimestamp());
        }
        // Percobaan ke-5 gagal → permanen.
        $this->s->clock->advance('+2 hours');
        $this->plan = ['curl_28'];
        $this->notifier->dispatchPending();
        $n = $this->notes->findById($id);
        self::assertSame(5, $n?->attempts);
        self::assertSame('curl_28', $n->lastError);
        self::assertSame(1, $this->notes->countPermanentFailures());
        self::assertSame(1, (int) $this->s->db->pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE event = 'logout.notify_failed'")->fetchColumn());

        $this->sent = [];
        $this->s->clock->advance('+1 day');
        self::assertSame(0, $this->notifier->dispatchPending());
        self::assertSame([], $this->sent, 'tidak dicoba lagi setelah permanen');

        // Admin retry menghidupkan kembali.
        self::assertSame(1, $this->notes->resetFailed($this->s->clock->now()));
        $this->plan = [200];
        self::assertSame(1, $this->notifier->dispatchPending());
        self::assertNotNull($this->notes->findById($id)?->deliveredAt);
    }

    public function testRetryRetriesBothSuccessCodes(): void
    {
        [$session] = $this->login();
        $this->plan = [200, 302];
        $this->logout->endSession($session->sid, 'test');
        $states = array_map(static fn ($n): bool => $n->deliveredAt !== null, $this->notes->listBySid($session->sid));
        sort($states);
        self::assertSame([false, true], $states, '200 terkirim; 302 (redirect) dianggap gagal, tidak diikuti');
        $fail = array_values(array_filter($this->notes->listBySid($session->sid), static fn ($n): bool => $n->deliveredAt === null))[0];
        self::assertSame('http_302', $fail->lastError);
    }

    public function testSsrfBackchannelUriBlockedAtSendTime(): void
    {
        foreach (['https://127.0.0.1/bcl', 'https://169.254.169.254/latest', 'https://10.0.0.7/x', 'http://a.example.test/bcl', 'https://localhost/x'] as $bad) {
            $this->s->db->pdo()->prepare('UPDATE clients SET backchannel_logout_uri = ? WHERE id = ?')->execute([$bad, $this->appA->id]);
            $this->s->db->pdo()->exec('DELETE FROM logout_notifications');
            [$session] = $this->login();
            $this->sent = [];
            $this->plan = [];
            $this->logout->endSession($session->sid, 'test');
            self::assertSame(['https://b.example.test/bcl'], array_column($this->sent, 'url'), 'hanya B terkirim; ' . $bad . ' diblokir');
            $blocked = array_values(array_filter($this->notes->listBySid($session->sid), fn ($n): bool => $n->clientId === $this->appA->id));
            self::assertSame('url_blocked', $blocked[0]->lastError);
            self::assertNull($blocked[0]->deliveredAt);
        }
    }

    public function testEndAllForUserExceptCurrentAndOtherUserUntouched(): void
    {
        [$s1] = $this->login();
        [$s2] = $this->login();
        $other = $this->s->users->create('budi@example.test', null, 'Budi');
        [$s3] = $this->login($other);
        $this->logout->endAllForUser($this->user->id, $s1->sid, 'password_change');
        self::assertTrue($this->active($s1->sid));
        self::assertFalse($this->active($s2->sid));
        self::assertTrue($this->active($s3->sid));
        self::assertCount(2, $this->notes->listBySid($s2->sid));
        self::assertSame([], $this->notes->listBySid($s1->sid));
    }

    public function testNotificationsDeferredInsideCallerTransaction(): void
    {
        [$session] = $this->login();
        $this->s->db->transaction(function () use ($session): void {
            $this->logout->endSession($session->sid, 'tx');
            self::assertSame([], $this->sent, 'tidak mengirim sebelum transaksi pemanggil commit');
        });
        self::assertCount(1, $this->deferred);
        ($this->deferred[0])();
        self::assertCount(2, $this->sent);
    }

    public function testRollbackKeepsSessionAndSendsNothing(): void
    {
        [$session] = $this->login();
        try {
            $this->s->db->transaction(function () use ($session): void {
                $this->logout->endSession($session->sid, 'tx');
                throw new \RuntimeException('gagal');
            });
        } catch (\RuntimeException) {
        }
        self::assertTrue($this->active($session->sid));
        self::assertSame([], $this->notes->listBySid($session->sid));
    }

    // ---- /logout (RP-initiated) ----

    public function testRpInitiatedLogoutRedirectsExactMatchWithState(): void
    {
        [$session, $cookies] = $this->login();
        $r = $this->controller->confirm($this->req('GET', '/logout', [
            'id_token_hint' => $this->hint($session, $this->appA), 'post_logout_redirect_uri' => self::BYE, 'state' => 'xyz 1&2',
        ], [], $cookies));
        self::assertSame(302, $r->status);
        self::assertSame(self::BYE . '?state=xyz%201%262', $r->headers['Location']);
        self::assertFalse($this->active($session->sid));
        self::assertSame(1, $r->cookies[0]->expires);
        self::assertCount(1, $this->deferred);
        ($this->deferred[0])();
        self::assertCount(2, $this->sent, 'logout token ke A dan B setelah respons');
    }

    public function testRpLogoutAcceptsExpiredIdToken(): void
    {
        [$session] = $this->login();
        $hint = $this->hint($session, $this->appA);
        $this->s->clock->advance('+2 hours');
        $r = $this->controller->confirm($this->req('GET', '/logout', ['id_token_hint' => $hint, 'post_logout_redirect_uri' => self::BYE]));
        self::assertSame(self::BYE, $r->headers['Location'] ?? null);
        self::assertFalse($this->active($session->sid));
    }

    public function testOpenRedirectVariantsDoNotRedirect(): void
    {
        $variants = [
            'https://evil.example.test/', 'https://app.example.test/bye/', 'https://app.example.test/bye?x=1', 'HTTPS://APP.EXAMPLE.TEST/bye',
            'https://app.example.test/bye#f', 'https://app.example.test.evil.test/bye', '//evil.test/bye', 'javascript:alert(1)', ' ' . self::BYE,
            self::BYE . "\r\nSet-Cookie: x=1", 'https://app.example.test/BYE',
        ];
        foreach ($variants as $uri) {
            [$session] = $this->login();
            $r = $this->controller->confirm($this->req('GET', '/logout', ['id_token_hint' => $this->hint($session, $this->appA), 'post_logout_redirect_uri' => $uri]));
            self::assertSame(200, $r->status, $uri);
            self::assertArrayNotHasKey('Location', $r->headers, $uri);
            self::assertStringContainsString('Anda sudah keluar', $r->body);
            self::assertFalse($this->active($session->sid), 'logout tetap terjadi tanpa redirect');
        }
    }

    public function testPostLogoutUriOfAnotherClientNotAccepted(): void
    {
        $this->uris->add($this->appB->id, 'https://b.example.test/bye', 'post_logout');
        $this->uris->add($this->appA->id, 'https://app.example.test/login-cb', 'login');
        [$session] = $this->login();
        foreach (['https://b.example.test/bye', 'https://app.example.test/login-cb'] as $uri) {
            [$session] = $this->login();
            $r = $this->controller->confirm($this->req('GET', '/logout', ['id_token_hint' => $this->hint($session, $this->appA), 'post_logout_redirect_uri' => $uri]));
            self::assertArrayNotHasKey('Location', $r->headers, $uri);
        }
    }

    public function testNoHintNeverRedirectsAndNeverLogsOutOnGet(): void
    {
        [$session, $cookies] = $this->login();
        $r = $this->controller->confirm($this->req('GET', '/logout', ['post_logout_redirect_uri' => self::BYE, 'state' => 's'], [], $cookies));
        self::assertSame(200, $r->status);
        self::assertArrayNotHasKey('Location', $r->headers);
        self::assertStringContainsString('Ya, keluar', $r->body);
        self::assertTrue($this->active($session->sid));

        $anon = $this->controller->confirm($this->req('GET', '/logout', ['post_logout_redirect_uri' => 'https://evil.test/']));
        self::assertArrayNotHasKey('Location', $anon->headers);
        self::assertStringContainsString('Anda sudah keluar', $anon->body);
    }

    public function testForgedAndWrongTypeHintsAreRejected(): void
    {
        [$session, $cookies] = $this->login();
        $kid = $this->km->active()->kid;
        $claims = ['iss' => $this->issuer->issuer(), 'sub' => $this->user->uuid, 'aud' => 'cl_a', 'sid' => $session->sid, 'iat' => time(), 'exp' => time() + 600];
        $rogue = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rogue);
        openssl_pkey_export($rogue, $roguePem);
        $b64 = static fn (string $x): string => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
        $forged = [
            'kunci lain, kid sah' => JWT::encode($claims, $roguePem, 'RS256', $kid, ['typ' => 'JWT']),
            'kid tak dikenal' => JWT::encode($claims, $roguePem, 'RS256', 'kid-palsu', ['typ' => 'JWT']),
            'HS256 dengan kunci publik' => JWT::encode($claims, $this->km->active()->publicKeyPem, 'HS256', $kid, ['typ' => 'JWT']),
            'alg none' => $b64('{"alg":"none","typ":"JWT","kid":"' . $kid . '"}') . '.' . $b64((string) json_encode($claims)) . '.',
            'tanpa kid' => JWT::encode($claims, $roguePem, 'RS256', null, ['typ' => 'JWT']),
            'logout token dipakai sebagai hint' => $this->issuer->logoutToken($this->appA, $this->user->uuid, $session->sid),
            'access token sebagai hint' => $this->issuer->accessToken($this->user, $this->appA, 'openid', $session->sid),
            'sampah' => 'a.b.c',
            'terlalu panjang' => str_repeat('a', 9000),
        ];
        // Tanda tangan dirusak.
        $good = $this->hint($session, $this->appA);
        $forged['payload dimodifikasi'] = explode('.', $good)[0] . '.' . $b64((string) json_encode(['sid' => $session->sid, 'aud' => 'cl_a'] + $claims)) . '.' . explode('.', $good)[2];
        // Klaim tak cocok.
        $forged['iss salah'] = $this->km->sign(['iss' => 'https://evil.test'] + $claims, 'JWT');
        $forged['sub salah'] = $this->km->sign(['sub' => 'orang-lain'] + $claims, 'JWT');
        $forged['aud klien tak dikenal'] = $this->km->sign(['aud' => 'cl_x'] + $claims, 'JWT');
        $forged['klien belum login di sesi'] = $this->issuer->idToken($this->user, $this->clients->create('cl_z', null, 'Z', 'public', [], 'openid', 1, 1, null, $this->s->clock->now()), $session, 'openid', null);

        foreach ($forged as $label => $jwt) {
            $r = $this->controller->confirm($this->req('GET', '/logout', ['id_token_hint' => $jwt, 'post_logout_redirect_uri' => self::BYE], [], $cookies));
            self::assertArrayNotHasKey('Location', $r->headers, $label);
            self::assertTrue($this->active($session->sid), $label . ': sesi tidak boleh diakhiri');
        }
        self::assertSame([], $this->sent);
    }

    public function testHintForOtherUsersSessionCannotBeUsedWithMismatchedSub(): void
    {
        [$session] = $this->login();
        $other = $this->s->users->create('budi@example.test', null, 'Budi');
        $hint = $this->issuer->idToken($other, $this->appA, $session, 'openid', null);
        $this->controller->confirm($this->req('GET', '/logout', ['id_token_hint' => $hint, 'post_logout_redirect_uri' => self::BYE]));
        self::assertTrue($this->active($session->sid));
    }

    public function testSubmitEndsCurrentSessionOnly(): void
    {
        [$s1, $cookies] = $this->login();
        [$s2] = $this->login();
        $r = $this->controller->submit($this->req('POST', '/logout', [], [], $cookies));
        self::assertSame('/logged-out', $r->headers['Location']);
        self::assertSame(1, $r->cookies[0]->expires);
        self::assertFalse($this->active($s1->sid));
        self::assertTrue($this->active($s2->sid));
        self::assertCount(1, $this->deferred, 'pengiriman ditunda sampai respons terkirim');
        self::assertSame([], $this->sent);
        ($this->deferred[0])();
        self::assertCount(2, $this->sent);
        // Tanpa sesi: tetap aman.
        self::assertSame('/logged-out', $this->controller->submit($this->req('POST', '/logout'))->headers['Location']);
    }

    // ---- sesi aktif ----

    /**
     * @param array<string,string> $cookies
     * @param array<string,string> $params
     */
    private function authed(string $method, string $path, array $cookies, array $params = []): Request
    {
        $r = $this->req($method, $path, [], [], $cookies);
        $session = $this->s->manager->current($r);
        self::assertNotNull($session);
        $r = $r->withAttribute('sso_session', $session)->withAttribute('user', $this->s->users->findById($session->userId));
        foreach ($params as $k => $v) {
            $r = $r->withAttribute($k, $v);
        }

        return $r;
    }

    public function testSessionsPageListsOnlyOwnActiveSessions(): void
    {
        [$s1, $c1] = $this->login();
        [$s2] = $this->login();
        $other = $this->s->users->create('budi@example.test', null, 'Budi');
        [$s3] = $this->login($other);
        $r = $this->sessionsCtl->show($this->authed('GET', '/account/sessions', $c1));
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Firefox di Linux', $r->body);
        self::assertStringContainsString($s2->sid, $r->body);
        self::assertStringNotContainsString($s3->sid, $r->body);
        self::assertStringContainsString('Sesi ini', $r->body);
        self::assertStringNotContainsString($s1->sid, $r->body, 'sesi sendiri tidak punya tombol cabut');
    }

    public function testRevokeOneSessionAuthorization(): void
    {
        [$s1, $c1] = $this->login();
        [$s2] = $this->login();
        $other = $this->s->users->create('budi@example.test', null, 'Budi');
        [$s3] = $this->login($other);

        // Sesi milik user lain → 404 dan tetap aktif.
        try {
            $this->sessionsCtl->revoke($this->authed('POST', '/x', $c1, ['sid' => $s3->sid]));
            self::fail('harus 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
        self::assertTrue($this->active($s3->sid));
        foreach (['../etc', 'bukan-uuid', ''] as $bad) {
            try {
                $this->sessionsCtl->revoke($this->authed('POST', '/x', $c1, ['sid' => $bad]));
                self::fail('harus 404');
            } catch (HttpException $e) {
                self::assertSame(404, $e->status);
            }
        }

        $r = $this->sessionsCtl->revoke($this->authed('POST', '/x', $c1, ['sid' => $s2->sid]));
        self::assertSame('/account/sessions', $r->headers['Location']);
        self::assertFalse($this->active($s2->sid));
        self::assertTrue($this->active($s1->sid));
        self::assertCount(2, $this->notes->listBySid($s2->sid));
    }

    public function testRevokeAllEndsEverySessionIncludingCurrent(): void
    {
        [$s1, $c1] = $this->login();
        [$s2] = $this->login();
        $other = $this->s->users->create('budi@example.test', null, 'Budi');
        [$s3] = $this->login($other);
        $this->refreshToken($s2, $this->appA, 'hx');
        $r = $this->sessionsCtl->revokeAll($this->authed('POST', '/account/sessions/revoke-all', $c1));
        self::assertSame('/logged-out', $r->headers['Location']);
        self::assertSame(1, $r->cookies[0]->expires);
        self::assertFalse($this->active($s1->sid));
        self::assertFalse($this->active($s2->sid));
        self::assertTrue($this->active($s3->sid));
        self::assertNotNull($this->rt->findByHash('hx')?->revokedAt);
    }

    // ---- cron ----

    public function testCronRunnerRunsTasksAndSurvivesStubbedGc(): void
    {
        [$session] = $this->login();
        $this->plan = [500, 500];
        $this->logout->endSession($session->sid, 'test');
        $this->s->clock->advance('+2 minutes');
        $this->plan = [204, 204];
        $runner = new CronRunner($this->notifier, $this->km, new Gc(), new Logger(sys_get_temp_dir() . '/bsso-test-logs'));
        $out = $runner->run();
        self::assertSame('terkirim 2', $out['logout_retry']);
        self::assertSame('tidak ada', $out['rotate_keys']);
        self::assertSame('dilewati', $out['gc']);
    }
}
