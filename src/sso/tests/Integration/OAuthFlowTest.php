<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\AuthorizeController;
use App\Controller\ErrorController;
use App\Controller\IntrospectController;
use App\Controller\RevokeController;
use App\Controller\TokenController;
use App\Controller\UserInfoController;
use App\Http\Middleware\Csrf;
use App\Http\Middleware\RateLimit;
use App\Http\Request;
use App\Http\Response;
use App\OAuth\AccessTokenVerifier;
use App\OAuth\AuthCodeService;
use App\OAuth\ClientAuthenticator;
use App\OAuth\ClientCreator;
use App\OAuth\OAuthException;
use App\OAuth\Pkce;
use App\OAuth\RefreshTokenService;
use App\OAuth\Scopes;
use App\OAuth\TokenIssuer;
use App\Repository\AuthCodeRepository;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Repository\Record\Client;
use App\Repository\Record\User;
use App\Repository\RefreshTokenRepository;
use App\Repository\SessionClientRepository;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Security\KeyManager;
use App\Session\SsoSessionManager;
use App\Support\UiSession;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** SQLite in-memory + direktori kunci temp (BUKAN MariaDB): menguji logika OAuth, bukan dialek SQL produksi. */
final class OAuthFlowTest extends TestCase
{
    private const REDIRECT = 'https://app.example.test/cb?x=1';

    private Stack $s;
    private string $keyDir;
    private KeyManager $km;
    private ClientRepository $clients;
    private ClientRedirectUriRepository $uris;
    private AuthCodeRepository $codeRepo;
    private RefreshTokenRepository $rtRepo;
    private AuthCodeService $codes;
    private RefreshTokenService $refresh;
    private TokenIssuer $issuer;
    private AccessTokenVerifier $verifier;
    private ClientAuthenticator $clientAuth;
    private AuthorizeController $authorize;
    private TokenController $token;
    private UserInfoController $userinfo;
    private IntrospectController $introspect;
    private RevokeController $revoke;
    private User $user;
    private Client $app;
    private string $secret;
    private Client $spa;
    private Client $other;
    private string $otherSecret;

    private static string $hashApp;
    private static string $hashOther;

    public static function setUpBeforeClass(): void
    {
        $h = new \App\Security\PasswordHasher();
        self::$hashApp = $h->hash('secret-app-' . str_repeat('a', 30));
        self::$hashOther = $h->hash('secret-other-' . str_repeat('b', 30));
    }

    private function makeClient(string $id, ?string $hash, string $type, string $redirect): Client
    {
        $grants = $type === 'confidential' ? ['authorization_code', 'refresh_token', 'client_credentials'] : ['authorization_code', 'refresh_token'];
        $c = $this->clients->create($id, $hash, $id, $type, $grants, 'openid profile email offline_access', 900, 2592000, null, $this->s->clock->now());
        $this->uris->add($c->id, $redirect, 'login');

        return $c;
    }

    protected function setUp(): void
    {
        UiSession::fake();
        $s = $this->s = new Stack();
        $this->keyDir = sys_get_temp_dir() . '/bsso-oauth-' . bin2hex(random_bytes(6));
        $this->km = new KeyManager(new SigningKeyRepository($s->db), new SettingRepository($s->db), $s->db, $s->clock, $s->audit, $this->keyDir);
        $this->km->rotateIfDue();

        $this->clients = new ClientRepository($s->db);
        $this->uris = new ClientRedirectUriRepository($s->db);
        $this->codeRepo = new AuthCodeRepository($s->db);
        $this->rtRepo = new RefreshTokenRepository($s->db);
        $sessionClients = new SessionClientRepository($s->db);
        $this->codes = new AuthCodeService($this->codeRepo, $this->rtRepo, $sessionClients, $s->sessions, $s->users, new Pkce(), $s->db, $s->clock, $s->audit);
        $this->refresh = new RefreshTokenService($this->rtRepo, $s->sessions, $s->users, $s->terminator, $s->db, $s->clock, $s->audit);
        $this->issuer = new TokenIssuer($this->km, $s->urls, $s->clock);
        $this->verifier = new AccessTokenVerifier($this->km, $this->issuer, $s->users, $s->sessions, $this->clients, $s->clock);
        $this->clientAuth = new ClientAuthenticator($this->clients, $s->hasher);
        $this->authorize = new AuthorizeController($this->clients, $this->uris, $s->users, $s->manager, $s->terminator, $this->codes, new ErrorController($s->view));
        $this->token = new TokenController($this->clientAuth, $this->codes, $this->refresh, $this->issuer, $s->users, $s->sessions, $s->audit);
        $this->userinfo = new UserInfoController($this->verifier);
        $this->introspect = new IntrospectController($this->clientAuth, $this->verifier, $this->refresh, $s->users, $this->issuer);
        $this->revoke = new RevokeController($this->clientAuth, $this->refresh);

        // Hash secret dihitung sekali per kelas (Argon2id lambat); klien dibuat langsung lewat repository.
        $this->secret = 'secret-app-' . str_repeat('a', 30);
        $this->otherSecret = 'secret-other-' . str_repeat('b', 30);
        $this->app = $this->makeClient('cl_app', self::$hashApp, 'confidential', self::REDIRECT);
        $this->other = $this->makeClient('cl_other', self::$hashOther, 'confidential', 'https://other.example.test/cb');
        $this->spa = $this->makeClient('cl_spa', null, 'public', 'https://spa.example.test/cb');

        $this->user = $s->users->create('ani@example.test', null, 'Ani');
        $s->users->markEmailVerified($this->user->id, $s->clock->now());
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

    /** @return array{0: string, 1: string} [verifier, challenge] */
    private static function pkce(): array
    {
        $v = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$v, rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=')];
    }

    /** @return array<string,string> cookie sesi SSO */
    private function loginCookies(?User $user = null, bool $mfaPassed = false): array
    {
        $cookie = $this->s->manager->start(($user ?? $this->user)->id, 'password', $mfaPassed, $this->req('GET', '/'));

        return [SsoSessionManager::COOKIE => $cookie->value];
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     */
    private function req(string $method, string $path, array $query = [], array $body = [], array $headers = [], array $cookies = []): Request
    {
        return new Request($method, $path, $query, $body, $headers + ['user-agent' => 'PHPUnit'], $cookies, [], '198.51.100.7');
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function authQuery(string $challenge, array $extra = []): array
    {
        return $extra + [
            'response_type' => 'code', 'client_id' => $this->app->clientId, 'redirect_uri' => self::REDIRECT,
            'scope' => 'openid profile email offline_access', 'state' => 'st-123', 'nonce' => 'n-456',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ];
    }

    /** @return array<string,string> parameter query dari header Location */
    private static function locationParams(Response $r): array
    {
        $loc = $r->headers['Location'] ?? '';
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $out);

        return array_map('strval', $out);
    }

    private function basic(Client $c, string $secret): string
    {
        return 'Basic ' . base64_encode(urlencode($c->clientId) . ':' . urlencode($secret));
    }

    /** @return array<string,mixed> */
    private function json(Response $r): array
    {
        $d = json_decode($r->body, true);
        self::assertIsArray($d);

        return $d;
    }

    /** @param array<string,mixed> $body */
    private function tokenReq(array $body, ?Client $c = null, ?string $secret = null): Request
    {
        $c ??= $this->app;

        return $this->req('POST', '/token', [], $body, ['authorization' => $this->basic($c, $secret ?? $this->secret)]);
    }

    /**
     * Jalankan authorize penuh lalu tukar code.
     *
     * @return array{tokens: array<string,mixed>, code: string, verifier: string, cookies: array<string,string>}
     */
    private function fullFlow(?User $user = null): array
    {
        [$verifier, $challenge] = self::pkce();
        $cookies = $this->loginCookies($user);
        $resp = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($challenge), [], [], $cookies));
        self::assertSame(302, $resp->status);
        $params = self::locationParams($resp);
        self::assertArrayHasKey('code', $params);
        $tok = $this->token->token($this->tokenReq([
            'grant_type' => 'authorization_code', 'code' => $params['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ]));
        self::assertSame(200, $tok->status, $tok->body);

        return ['tokens' => $this->json($tok), 'code' => $params['code'], 'verifier' => $verifier, 'cookies' => $cookies];
    }

    /** @param array<string,mixed> $body */
    private function refreshWith(string $rt, array $body = []): Response
    {
        return $this->token->token($this->tokenReq(['grant_type' => 'refresh_token', 'refresh_token' => $rt] + $body));
    }

    private function audited(string $event): int
    {
        $stmt = $this->s->db->pdo()->prepare('SELECT COUNT(*) FROM audit_logs WHERE event = ?');
        $stmt->execute([$event]);

        return (int) $stmt->fetchColumn();
    }

    // ---- alur utama ----

    public function testFullAuthorizationCodeFlowAndTokenClaims(): void
    {
        $f = $this->fullFlow();
        $t = $f['tokens'];
        self::assertSame('Bearer', $t['token_type']);
        self::assertSame(900, $t['expires_in']);
        self::assertArrayHasKey('refresh_token', $t);

        $sid = $this->s->sessions->listActiveForUser($this->user->id, $this->s->clock->now())[0]->sid;
        $id = $this->km->verify($t['id_token']);
        self::assertSame($this->user->uuid, $id['sub']);
        self::assertSame($this->app->clientId, $id['aud']);
        self::assertSame('n-456', $id['nonce']);
        self::assertSame($sid, $id['sid']);
        self::assertSame(['pwd'], $id['amr']);
        self::assertSame('ani@example.test', $id['email']);
        self::assertSame('Ani', $id['name']);
        self::assertSame('https://sso.example.test', $id['iss']);

        $at = $this->km->verify($t['access_token']);
        self::assertSame('openid profile email offline_access', $at['scope']);
        self::assertSame($sid, $at['sid']);
        self::assertSame($this->app->clientId, $at['client_id']);
        $header = json_decode((string) base64_decode(strtr(explode('.', $t['access_token'])[0], '-_', '+/')), true);
        self::assertSame('at+jwt', $header['typ']);
        self::assertSame('RS256', $header['alg']);
        self::assertNotEmpty($header['kid']);

        $row = $this->s->db->pdo()->query('SELECT COUNT(*) FROM session_clients')->fetchColumn();
        self::assertSame(1, (int) $row);
        // code & refresh token hanya disimpan sebagai hash.
        $raw = $this->s->db->pdo()->query('SELECT code_hash FROM auth_codes')->fetchColumn();
        self::assertSame(64, strlen((string) $raw));
        self::assertNotSame($f['code'], $raw);
    }

    public function testTokenResponseIsNoStore(): void
    {
        $f = $this->fullFlow();
        $r = $this->token->token($this->tokenReq(['grant_type' => 'authorization_code', 'code' => $f['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $f['verifier']]));
        self::assertSame('no-store', $r->headers['Cache-Control']);
        self::assertSame('no-cache', $r->headers['Pragma']);
    }

    public function testScopeWithoutOfflineAccessGivesNoRefreshToken(): void
    {
        [$v, $c] = self::pkce();
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c, ['scope' => 'openid']), [], [], $this->loginCookies()));
        $tok = $this->json($this->token->token($this->tokenReq([
            'grant_type' => 'authorization_code', 'code' => self::locationParams($r)['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $v,
        ])));
        self::assertArrayNotHasKey('refresh_token', $tok);
        self::assertArrayNotHasKey('email', $this->km->verify($tok['id_token']));
    }

    public function testPublicClientWithPkceNoSecret(): void
    {
        [$v, $c] = self::pkce();
        $q = $this->authQuery($c, ['client_id' => $this->spa->clientId, 'redirect_uri' => 'https://spa.example.test/cb']);
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $q, [], [], $this->loginCookies()));
        $tok = $this->token->token($this->req('POST', '/token', [], [
            'grant_type' => 'authorization_code', 'client_id' => $this->spa->clientId, 'code' => self::locationParams($r)['code'],
            'redirect_uri' => 'https://spa.example.test/cb', 'code_verifier' => $v,
        ]));
        self::assertSame(200, $tok->status, $tok->body);
    }

    // ---- /authorize: validasi ----

    /** @return list<array{0: string}> */
    public static function badRedirects(): array
    {
        return [
            ['https://app.example.test/cb'], ['https://app.example.test/cb?x=2'], ['https://app.example.test/cb?x=1&y=2'],
            ['https://app.example.test/CB?x=1'], ['https://APP.example.test/cb?x=1'], ['https://app.example.test:8443/cb?x=1'],
            ['https://evil.app.example.test/cb?x=1'], ['https://app.example.test/cb/?x=1'], ['https://app.example.test/cb?x=1#f'],
            ['http://app.example.test/cb?x=1'], ['https://app.example.test/cb?x=1 '], ['https://app.example.test/cb?x=1/../cb'],
            ['https://app.example.test'], [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRedirects')]
    public function testRedirectUriMustMatchExactlyAndNeverRedirects(string $uri): void
    {
        [, $c] = self::pkce();
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c, ['redirect_uri' => $uri]), [], [], $this->loginCookies()));
        self::assertSame(400, $r->status);
        self::assertArrayNotHasKey('Location', $r->headers);
        self::assertSame(0, (int) $this->s->db->pdo()->query('SELECT COUNT(*) FROM auth_codes')->fetchColumn());
    }

    public function testUnknownAndDisabledClientShowErrorPage(): void
    {
        [, $c] = self::pkce();
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c, ['client_id' => 'tidak-ada'])));
        self::assertSame(401, $r->status);
        self::assertArrayNotHasKey('Location', $r->headers);

        $this->s->db->pdo()->exec('UPDATE clients SET is_enabled = 0 WHERE id = ' . $this->app->id);
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c)));
        self::assertSame(401, $r->status);
        self::assertArrayNotHasKey('Location', $r->headers);
    }

    /** @return list<array{0: array<string,mixed>, 1: string}> */
    public static function badAuthorize(): array
    {
        return [
            [['code_challenge_method' => 'plain'], 'invalid_request'],
            [['code_challenge_method' => null], 'invalid_request'],
            [['code_challenge' => null], 'invalid_request'],
            [['code_challenge' => 'pendek'], 'invalid_request'],
            [['response_type' => 'token'], 'unsupported_response_type'],
            [['scope' => 'openid admin'], 'invalid_scope'],
            [['nonce' => str_repeat('n', 129)], 'invalid_request'],
            [['state' => str_repeat('s', 513)], 'invalid_request'],
        ];
    }

    /**
     *
     * @param array<string,mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badAuthorize')]
    public function testInvalidAuthorizeRequestRedirectsOAuthErrorWithState(array $override, string $error): void
    {
        [, $c] = self::pkce();
        $q = array_filter($override + $this->authQuery($c), static fn (mixed $v): bool => $v !== null);
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $q, [], [], $this->loginCookies()));
        self::assertSame(302, $r->status);
        $p = self::locationParams($r);
        self::assertSame($error, $p['error']);
        self::assertStringStartsWith(self::REDIRECT . '&error=', $r->headers['Location']);
        if (($override['state'] ?? null) === null) {
            self::assertSame('st-123', $p['state']);
        }
        self::assertArrayNotHasKey('code', $p);
    }

    public function testNoSessionRedirectsToLoginWithOriginalRequestAndPromptNone(): void
    {
        [, $c] = self::pkce();
        $q = $this->authQuery($c);
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $q));
        self::assertSame(302, $r->status);
        self::assertStringStartsWith('/login?return=', $r->headers['Location']);
        $return = rawurldecode(substr($r->headers['Location'], strlen('/login?return=')));
        self::assertSame('/authorize?' . http_build_query($q), $return);
        self::assertSame($return, \App\Support\SafePath::clean($return));

        $r = $this->authorize->authorize($this->req('GET', '/authorize', $q + ['prompt' => 'none']));
        self::assertSame('login_required', self::locationParams($r)['error']);
        self::assertSame('st-123', self::locationParams($r)['state']);
    }

    public function testSilentLoginIssuesCodeWithExistingSession(): void
    {
        [, $c] = self::pkce();
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c, ['prompt' => 'none']), [], [], $this->loginCookies()));
        self::assertArrayHasKey('code', self::locationParams($r));
        self::assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function testPreMfaSessionIsNotTreatedAsLoggedIn(): void
    {
        $this->s->users->enableMfa($this->user->id, 'enc', ['h'], 1, $this->s->clock->now());
        [, $c] = self::pkce();
        $cookies = $this->loginCookies(null, false); // sesi tanpa MFA lulus
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c), [], [], $cookies));
        self::assertStringStartsWith('/login?return=', $r->headers['Location']);
        self::assertSame(0, (int) $this->s->db->pdo()->query('SELECT COUNT(*) FROM auth_codes')->fetchColumn());
        self::assertCount(0, $this->s->sessions->listActiveForUser($this->user->id, $this->s->clock->now()), 'sesi pra-MFA diakhiri');

        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c, ['prompt' => 'none']), [], [], $this->loginCookies(null, false)));
        self::assertSame('login_required', self::locationParams($r)['error']);

        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c), [], [], $this->loginCookies(null, true)));
        self::assertArrayHasKey('code', self::locationParams($r));
    }

    public function testSuspendedUserSessionDoesNotGetCode(): void
    {
        $cookies = $this->loginCookies();
        $this->s->db->pdo()->exec("UPDATE users SET status = 'suspended'");
        [, $c] = self::pkce();
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c), [], [], $cookies));
        self::assertStringStartsWith('/login?return=', $r->headers['Location']);
    }

    // ---- /token: code ----

    public function testAuthorizationCodeIsSingleUseAndReuseRevokesRefreshTokens(): void
    {
        $f = $this->fullFlow();
        $rt = $f['tokens']['refresh_token'];
        $body = ['grant_type' => 'authorization_code', 'code' => $f['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $f['verifier']];
        $again = $this->token->token($this->tokenReq($body));
        self::assertSame(400, $again->status);
        self::assertSame('invalid_grant', $this->json($again)['error']);
        self::assertSame(1, $this->audited('token.code_reuse'));
        self::assertSame('invalid_grant', $this->json($this->refreshWith($rt))['error'], 'refresh dari code itu dicabut');
    }

    public function testWrongOrMissingPkceVerifierRejected(): void
    {
        [$v, $c] = self::pkce();
        $r = $this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c), [], [], $this->loginCookies()));
        $code = self::locationParams($r)['code'];
        $base = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT];
        [$other] = self::pkce();
        self::assertSame('invalid_grant', $this->json($this->token->token($this->tokenReq($base + ['code_verifier' => $other])))['error']);
        self::assertSame('invalid_grant', $this->json($this->token->token($this->tokenReq($base + ['code_verifier' => 'pendek'])))['error']);
        self::assertSame('invalid_request', $this->json($this->token->token($this->tokenReq($base)))['error']);
        self::assertSame(200, $this->token->token($this->tokenReq($base + ['code_verifier' => $v]))->status, 'verifier benar tetap bisa');
    }

    public function testCodeBoundToClientRedirectAndExpiry(): void
    {
        [$v, $c] = self::pkce();
        $mk = fn (): string => self::locationParams($this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c), [], [], $this->loginCookies())))['code'];
        $base = fn (string $code): array => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $v];

        $code = $mk();
        $r = $this->token->token($this->tokenReq($base($code), $this->other, $this->otherSecret));
        self::assertSame('invalid_grant', $this->json($r)['error'], 'code milik klien lain');

        $r = $this->token->token($this->tokenReq(['redirect_uri' => 'https://app.example.test/cb'] + $base($mk())));
        self::assertSame('invalid_grant', $this->json($r)['error']);

        $code = $mk();
        $this->s->clock->advance('+61 seconds');
        self::assertSame('invalid_grant', $this->json($this->token->token($this->tokenReq($base($code))))['error']);
    }

    public function testCodeOfOtherClientRejected(): void
    {
        [$v, $c] = self::pkce();
        $code = self::locationParams($this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c), [], [], $this->loginCookies())))['code'];
        $r = $this->token->token($this->tokenReq(
            ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://other.example.test/cb', 'code_verifier' => $v],
            $this->other,
            $this->otherSecret,
        ));
        self::assertSame('invalid_grant', $this->json($r)['error']);
    }

    public function testCodeRejectedWhenSessionRevoked(): void
    {
        [$v, $c] = self::pkce();
        $cookies = $this->loginCookies();
        $code = self::locationParams($this->authorize->authorize($this->req('GET', '/authorize', $this->authQuery($c), [], [], $cookies)))['code'];
        $this->s->terminator->endAllForUser($this->user->id, null, 'test');
        $r = $this->token->token($this->tokenReq(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $v]));
        self::assertSame('invalid_grant', $this->json($r)['error']);
    }

    // ---- refresh ----

    public function testRefreshRotationAndReuseDetectionRevokesFamilyAndSession(): void
    {
        $f = $this->fullFlow();
        $rt1 = $f['tokens']['refresh_token'];
        $r2 = $this->refreshWith($rt1);
        self::assertSame(200, $r2->status, $r2->body);
        $t2 = $this->json($r2);
        self::assertNotSame($rt1, $t2['refresh_token']);
        self::assertArrayHasKey('id_token', $t2);
        $r3 = $this->json($this->refreshWith($t2['refresh_token']));
        self::assertArrayHasKey('refresh_token', $r3);

        // rt1 dipakai ulang: seluruh family + sesi dicabut.
        $re = $this->refreshWith($rt1);
        self::assertSame('invalid_grant', $this->json($re)['error']);
        self::assertSame(1, $this->audited('token.reuse_detected'));
        self::assertSame('invalid_grant', $this->json($this->refreshWith($r3['refresh_token']))['error']);
        self::assertCount(0, $this->s->sessions->listActiveForUser($this->user->id, $this->s->clock->now()));
        $introspect = $this->introspect->introspect($this->req('POST', '/introspect', [], ['token' => $r3['access_token']], ['authorization' => $this->basic($this->app, $this->secret)]));
        self::assertFalse($this->json($introspect)['active']);
    }

    public function testRefreshTokenBoundToClientAndExpiryAndSuspension(): void
    {
        $f = $this->fullFlow();
        $rt = $f['tokens']['refresh_token'];
        $other = $this->token->token($this->tokenReq(['grant_type' => 'refresh_token', 'refresh_token' => $rt], $this->other, $this->otherSecret));
        self::assertContains($this->json($other)['error'], ['invalid_grant', 'unauthorized_client']);
        self::assertSame(200, $this->refreshWith($rt)->status, 'klien lain tak membakar token');

        $f = $this->fullFlow();
        $this->s->clock->advance('+31 days');
        self::assertSame('invalid_grant', $this->json($this->refreshWith($f['tokens']['refresh_token']))['error']);
    }

    public function testRefreshScopeNarrowingOnly(): void
    {
        $f = $this->fullFlow();
        $ok = $this->json($this->refreshWith($f['tokens']['refresh_token'], ['scope' => 'openid email']));
        self::assertSame('openid email', $ok['scope']);
        $bad = $this->refreshWith($ok['refresh_token'], ['scope' => 'openid admin']);
        self::assertSame('invalid_scope', $this->json($bad)['error']);
    }

    public function testRefreshFailsWhenUserSuspendedOrSessionRevoked(): void
    {
        $f = $this->fullFlow();
        $this->s->db->pdo()->exec("UPDATE users SET status = 'suspended'");
        self::assertSame('invalid_grant', $this->json($this->refreshWith($f['tokens']['refresh_token']))['error']);
    }

    // ---- client credentials & client auth ----

    public function testClientCredentialsGrant(): void
    {
        $r = $this->token->token($this->tokenReq(['grant_type' => 'client_credentials', 'scope' => 'profile']));
        self::assertSame(200, $r->status, $r->body);
        $t = $this->json($r);
        self::assertArrayNotHasKey('id_token', $t);
        self::assertArrayNotHasKey('refresh_token', $t);
        $c = $this->km->verify($t['access_token']);
        self::assertSame($this->app->clientId, $c['sub']);
        self::assertArrayNotHasKey('sid', $c);
        self::assertSame('profile', $c['scope']);
        self::assertSame('invalid_scope', $this->json($this->token->token($this->tokenReq(['grant_type' => 'client_credentials', 'scope' => 'x'])))['error']);

        $r = $this->token->token($this->req('POST', '/token', [], ['grant_type' => 'client_credentials', 'client_id' => $this->spa->clientId]));
        self::assertSame('unauthorized_client', $this->json($r)['error'], 'public client tanpa grant');
        $this->s->db->pdo()->exec("UPDATE clients SET allowed_grants = 'client_credentials' WHERE id = " . $this->spa->id);
        $r = $this->token->token($this->req('POST', '/token', [], ['grant_type' => 'client_credentials', 'client_id' => $this->spa->clientId]));
        self::assertSame('unauthorized_client', $this->json($r)['error'], 'public client dilarang client_credentials');
        // token client_credentials tidak bisa dipakai di /userinfo.
        self::assertSame(401, $this->userinfo->show($this->req('GET', '/userinfo', [], [], ['authorization' => 'Bearer ' . $t['access_token']]))->status);
    }

    public function testClientAuthentication(): void
    {
        $bad = $this->token->token($this->tokenReq(['grant_type' => 'client_credentials'], $this->app, 'salah'));
        self::assertSame(401, $bad->status);
        self::assertSame('invalid_client', $this->json($bad)['error']);
        self::assertStringStartsWith('Basic', $bad->headers['WWW-Authenticate']);

        $post = $this->token->token($this->req('POST', '/token', [], ['grant_type' => 'client_credentials', 'client_id' => $this->app->clientId, 'client_secret' => $this->secret]));
        self::assertSame(200, $post->status);

        $both = $this->token->token($this->req('POST', '/token', [], ['grant_type' => 'client_credentials', 'client_id' => $this->app->clientId, 'client_secret' => $this->secret], ['authorization' => $this->basic($this->app, $this->secret)]));
        self::assertSame('invalid_request', $this->json($both)['error']);

        $none = $this->token->token($this->req('POST', '/token', [], ['grant_type' => 'client_credentials', 'client_id' => $this->app->clientId]));
        self::assertSame(401, $none->status, 'confidential wajib secret');
        self::assertSame(401, $this->token->token($this->req('POST', '/token', [], ['grant_type' => 'client_credentials', 'client_id' => 'x', 'client_secret' => 'y']))->status);

        self::assertSame('unsupported_grant_type', $this->json($this->token->token($this->tokenReq(['grant_type' => 'password'])))['error']);
        self::assertSame('invalid_request', $this->json($this->token->token($this->tokenReq([])))['error']);
        $stored = $this->s->db->pdo()->query('SELECT client_secret_hash FROM clients WHERE id = ' . $this->app->id)->fetchColumn();
        self::assertStringNotContainsString($this->secret, (string) $stored);
    }

    public function testGrantNotAllowedForClient(): void
    {
        $this->s->db->pdo()->exec("UPDATE clients SET allowed_grants = 'authorization_code' WHERE id = " . $this->app->id);
        $r = $this->token->token($this->tokenReq(['grant_type' => 'client_credentials']));
        self::assertSame('unauthorized_client', $this->json($r)['error']);
    }

    public function testTokenRateLimitMiddleware(): void
    {
        $mw = new RateLimit($this->s->limiter);
        $req = $this->tokenReq(['grant_type' => 'client_credentials']);
        $next = static fn (Request $r): Response => new Response(200);
        for ($i = 0; $i < 60; $i++) {
            self::assertSame(200, $mw->process($req, $next)->status);
        }
        $r = $mw->process($req, $next);
        self::assertSame(429, $r->status);
        self::assertSame('60', $r->headers['Retry-After']);
        self::assertSame('no-store', $r->headers['Cache-Control']);
    }

    // ---- /userinfo ----

    public function testUserInfoByScope(): void
    {
        $f = $this->fullFlow();
        $r = $this->userinfo->show($this->req('GET', '/userinfo', [], [], ['authorization' => 'Bearer ' . $f['tokens']['access_token']]));
        self::assertSame(200, $r->status);
        self::assertSame('no-store', $r->headers['Cache-Control']);
        self::assertSame(['sub' => $this->user->uuid, 'email' => 'ani@example.test', 'email_verified' => true, 'name' => 'Ani'], $this->json($r));
        // POST juga diterima.
        self::assertSame(200, $this->userinfo->show($this->req('POST', '/userinfo', [], [], ['authorization' => 'Bearer ' . $f['tokens']['access_token']]))->status);

        $narrow = $this->json($this->refreshWith($f['tokens']['refresh_token'], ['scope' => 'openid']));
        $r = $this->userinfo->show($this->req('GET', '/userinfo', [], [], ['authorization' => 'Bearer ' . $narrow['access_token']]));
        self::assertSame(['sub' => $this->user->uuid], $this->json($r));
    }

    public function testUserInfoRejectsIdTokenMissingAndRevokedSession(): void
    {
        $f = $this->fullFlow();
        $get = fn (?string $tok): Response => $this->userinfo->show($this->req('GET', '/userinfo', [], [], $tok === null ? [] : ['authorization' => 'Bearer ' . $tok]));
        self::assertSame(401, $get(null)->status);
        self::assertSame(401, $get($f['tokens']['id_token'])->status, 'id_token bukan access token');
        self::assertStringContainsString('invalid_token', $get('abc.def.ghi')->headers['WWW-Authenticate']);
        $this->s->clock->advance('+16 minutes');
        self::assertSame(401, $get($f['tokens']['access_token'])->status, 'kedaluwarsa');
        $this->s->clock->advance('-16 minutes');
        self::assertSame(200, $get($f['tokens']['access_token'])->status);
        $this->s->terminator->endAllForUser($this->user->id, null, 'test');
        self::assertSame(401, $get($f['tokens']['access_token'])->status, 'sesi dicabut');
    }

    public function testForgedJwtsAlgNoneHs256TamperedUnknownKidRejected(): void
    {
        $f = $this->fullFlow();
        $claims = $this->km->verify($f['tokens']['access_token']);
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $kid = json_decode((string) base64_decode(strtr(explode('.', $f['tokens']['access_token'])[0], '-_', '+/')), true)['kid'];
        $get = fn (string $tok): Response => $this->userinfo->show($this->req('GET', '/userinfo', [], [], ['authorization' => 'Bearer ' . $tok]));

        $none = $b64((string) json_encode(['alg' => 'none', 'typ' => 'at+jwt', 'kid' => $kid])) . '.' . $b64((string) json_encode($claims)) . '.';
        self::assertSame(401, $get($none)->status);
        $none2 = $b64((string) json_encode(['alg' => 'none', 'typ' => 'at+jwt'])) . '.' . $b64((string) json_encode($claims)) . '.sig';
        self::assertSame(401, $get($none2)->status);

        $hs = JWT::encode($claims, str_repeat('k', 32), 'HS256', $kid, ['typ' => 'at+jwt']);
        self::assertSame(401, $get($hs)->status);
        $unknown = JWT::encode($claims, $this->privatePem(), 'RS256', 'kid-tak-dikenal', ['typ' => 'at+jwt']);
        self::assertSame(401, $get($unknown)->status);

        [$h, $p, $sg] = explode('.', $f['tokens']['access_token']);
        $evil = $claims;
        $evil['scope'] = 'openid admin';
        self::assertSame(401, $get($h . '.' . $b64((string) json_encode($evil)) . '.' . $sg)->status);
        // sub dipalsukan tetapi ditandatangani kunci aktif dengan sub berbeda dari sesi.
        $evil = $this->km->sign(['sub' => 'orang-lain'] + $claims, 'at+jwt');
        self::assertSame(401, $get($evil)->status);
    }

    private function privatePem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    // ---- /introspect & /revoke ----

    public function testIntrospectAccessAndRefreshTokens(): void
    {
        $f = $this->fullFlow();
        $call = fn (string $tok, ?Client $c = null, ?string $sec = null, array $extra = []): Response => $this->introspect->introspect(
            $this->req('POST', '/introspect', [], ['token' => $tok] + $extra, ['authorization' => $this->basic($c ?? $this->app, $sec ?? $this->secret)]),
        );
        $a = $this->json($call($f['tokens']['access_token']));
        self::assertTrue($a['active']);
        self::assertSame($this->user->uuid, $a['sub']);
        self::assertSame($this->app->clientId, $a['client_id']);
        self::assertSame('Bearer', $a['token_type']);
        self::assertSame('no-store', $call($f['tokens']['access_token'])->headers['Cache-Control']);

        $rt = $this->json($call($f['tokens']['refresh_token'], null, null, ['token_type_hint' => 'refresh_token']));
        self::assertTrue($rt['active']);
        self::assertSame(['active' => false], $this->json($call('sampah')));
        self::assertSame(['active' => false], $this->json($call($f['tokens']['id_token'])), 'id_token bukan access token');
        // klien lain tidak boleh melihat token klien ini.
        self::assertSame(['active' => false], $this->json($call($f['tokens']['access_token'], $this->other, $this->otherSecret)));
        self::assertSame(['active' => false], $this->json($call($f['tokens']['refresh_token'], $this->other, $this->otherSecret)));

        $this->s->terminator->endAllForUser($this->user->id, null, 'test');
        self::assertSame(['active' => false], $this->json($call($f['tokens']['access_token'])));
        self::assertSame(['active' => false], $this->json($call($f['tokens']['refresh_token'])));
    }

    public function testIntrospectRequiresConfidentialClient(): void
    {
        $f = $this->fullFlow();
        $r = $this->introspect->introspect($this->req('POST', '/introspect', [], ['token' => $f['tokens']['access_token'], 'client_id' => $this->spa->clientId]));
        self::assertSame(401, $r->status);
        $r = $this->introspect->introspect($this->req('POST', '/introspect', [], ['token' => 'x']));
        self::assertSame(401, $r->status);
        $r = $this->introspect->introspect($this->req('POST', '/introspect', [], [], ['authorization' => $this->basic($this->app, $this->secret)]));
        self::assertSame(400, $r->status);
    }

    public function testRevokeRefreshTokenRevokesFamilyAndIgnoresOthers(): void
    {
        $f = $this->fullFlow();
        $rt = $f['tokens']['refresh_token'];
        $rotated = $this->json($this->refreshWith($rt))['refresh_token'];

        $r = $this->revoke->revoke($this->req('POST', '/revoke', [], ['token' => $rotated], ['authorization' => $this->basic($this->other, $this->otherSecret)]));
        self::assertSame(200, $r->status, 'klien lain: 200 tapi tanpa efek');
        self::assertSame(200, $this->refreshWith($rotated)->status);

        $f2 = $this->fullFlow();
        $r = $this->revoke->revoke($this->req('POST', '/revoke', [], ['token' => $f2['tokens']['refresh_token']], ['authorization' => $this->basic($this->app, $this->secret)]));
        self::assertSame(200, $r->status);
        self::assertSame('', $r->body);
        self::assertSame('invalid_grant', $this->json($this->refreshWith($f2['tokens']['refresh_token']))['error']);

        $r = $this->revoke->revoke($this->req('POST', '/revoke', [], ['token' => 'tak-dikenal'], ['authorization' => $this->basic($this->app, $this->secret)]));
        self::assertSame(200, $r->status);
        self::assertSame(401, $this->revoke->revoke($this->req('POST', '/revoke', [], ['token' => 'x']))->status);
    }

    // ---- unit kecil ----

    public function testPkceAndScopes(): void
    {
        $p = new Pkce();
        // Vektor RFC 7636 Appendix B.
        self::assertTrue($p->verifyS256('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        self::assertFalse($p->verifyS256('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXl', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        self::assertFalse($p->verifyS256('', ''));
        self::assertFalse($p->verifyS256('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'), 'plain != S256');

        $s = new Scopes();
        self::assertSame('openid email', $s->intersect('openid x email openid', 'openid profile email'));
        self::assertSame('', $s->intersect('', 'openid'));
        self::assertSame([], Scopes::split('open"id'));
    }

    public function testCsrfExemptionLimitedToTokenEndpoints(): void
    {
        foreach (['/token', '/introspect', '/revoke', '/userinfo'] as $path) {
            self::assertTrue(Csrf::isExempt($this->req('POST', $path)), $path);
        }
        foreach (['/login', '/login/mfa', '/authorize', '/account', '/logout', '/admin/clients'] as $path) {
            self::assertFalse(Csrf::isExempt($this->req('POST', $path)), $path);
        }
    }

    public function testClientCreatorValidation(): void
    {
        $creator = new ClientCreator($this->clients, $this->uris, $this->s->hasher, $this->s->db, $this->s->clock);
        foreach (['http://evil.example/cb', 'https://a.example/*', 'https://a.example/cb#x', 'javascript:alert(1)', 'https://u:p@a.example/cb'] as $bad) {
            try {
                $creator->create('X', 'confidential', [$bad]);
                self::fail('harus ditolak: ' . $bad);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $r = $creator->create('Dev', 'confidential', ['http://localhost:3000/cb'], ['https://a.example/bye']);
        self::assertCount(1, $this->uris->listForClient($r['client']->id, 'post_logout'));
        self::assertNull($creator->create('Pub', 'public', ['https://p.example/cb'])['secret']);
        self::assertNotNull($creator->create('M2M', 'confidential', [], [], ['client_credentials'])['secret'], 'client_credentials tanpa redirect');
        try {
            $creator->create('Web', 'confidential', [], [], ['authorization_code']);
            self::fail('authorization_code wajib redirect');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $creator->create('Pub', 'public', ['https://p.example/cb'], [], ['client_credentials']);
    }

    public function testOAuthExceptionDefaults(): void
    {
        $e = new OAuthException('invalid_request', 'x');
        self::assertSame(400, $e->status);
        self::assertNull($e->redirectUri);
    }
}
