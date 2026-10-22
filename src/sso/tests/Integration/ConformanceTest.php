<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\OAuth\ClientCreator;
use App\Repository\ClientRedirectUriRepository;
use App\Repository\ClientRepository;
use App\Security\KeyManager;
use App\Session\SsoSessionManager;
use App\Support\Container;
use App\Support\UiSession;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/**
 * Conformance OIDC end-to-end lewat Kernel + routes.php + wiring bootstrap.php (SQLite in-memory):
 * discovery -> authorize -> token -> userinfo -> introspect -> refresh (rotasi + reuse) -> logout (RP-initiated).
 */
final class ConformanceTest extends TestCase
{
    private const REDIRECT = 'https://spa.example.test/cb';
    private const BYE = 'https://spa.example.test/bye';
    private const ISS = 'https://sso.example.test';

    private Stack $s;
    private Kernel $kernel;
    private string $keyDir;

    protected function setUp(): void
    {
        UiSession::fake();
        $this->s = new Stack();
        $this->keyDir = sys_get_temp_dir() . '/bsso-conf-' . bin2hex(random_bytes(6));
        /** @var Container $c */
        $c = require __DIR__ . '/../../bootstrap.php';
        $s = $this->s;
        $c->set('config.app', ['base_url' => self::ISS, 'key_dir' => $this->keyDir, 'debug' => false]);
        $c->set('config.secrets', ['hmac_key' => str_repeat('h', 32), 'keyring' => ['current' => 'v1', 'keys' => ['v1' => base64_encode(str_repeat('a', 32))]]]);
        $c->set(\App\Support\Database::class, $s->db);
        $c->set(\App\Support\Clock::class, $s->clock);
        $c->set(\App\Support\Logger::class, $s->logger);
        $c->set(\App\Support\Mailer::class, $s->mailer);
        $this->kernel = $c->get(Kernel::class);
        $c->get(KeyManager::class)->rotateIfDue();
    }

    protected function tearDown(): void
    {
        UiSession::reset();
        JWT::$timestamp = null;
        foreach (glob($this->keyDir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->keyDir)) {
            rmdir($this->keyDir);
        }
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     */
    private function call(string $method, string $path, array $query = [], array $body = [], array $headers = [], array $cookies = []): Response
    {
        return $this->kernel->handle(new Request($method, $path, $query, $body, $headers + ['user-agent' => 'PHPUnit'], $cookies, [], '198.51.100.7'));
    }

    /** @return array<string,mixed> */
    private function json(Response $r): array
    {
        $data = json_decode($r->body, true);
        self::assertIsArray($data, $r->body);

        return $data;
    }

    /** @return array<string,string> */
    private function params(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $out);

        return array_map('strval', $out);
    }

    public function testAlurOidcLengkapDariAuthorizeSampaiLogout(): void
    {
        $s = $this->s;
        $creator = new ClientCreator(new ClientRepository($s->db), new ClientRedirectUriRepository($s->db), $s->hasher, $s->db, $s->clock);
        $client = $creator->create('SPA', 'public', [self::REDIRECT], [self::BYE])['client'];
        $user = $s->users->create('ani@example.test', null, 'Ani');
        $s->users->markEmailVerified($user->id, $s->clock->now());
        $cookie = $s->manager->start($user->id, 'password', false, new Request('POST', '/login', [], [], [], [], [], '198.51.100.7'))->value;
        $cookies = [SsoSessionManager::COOKIE => $cookie];

        // Discovery & JWKS
        $disc = $this->json($this->call('GET', '/.well-known/openid-configuration'));
        self::assertSame(self::ISS, $disc['issuer']);
        $jwks = $this->json($this->call('GET', '/jwks'));
        self::assertNotEmpty($jwks['keys']);

        // Authorize (PKCE S256)
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $auth = $this->call('GET', '/authorize', [
            'client_id' => $client->clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid profile email offline_access',
            'state' => 'st-123', 'nonce' => 'nn-456', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], [], [], $cookies);
        self::assertSame(302, $auth->status);
        $loc = $auth->headers['Location'];
        self::assertStringStartsWith(self::REDIRECT . '?', $loc);
        $p = $this->params($loc);
        self::assertSame('st-123', $p['state']);
        self::assertNotSame('', $p['code']);

        // Token: verifier salah ditolak, code tidak hangus oleh verifier salah? (sekali pakai: gunakan yang benar)
        $form = ['grant_type' => 'authorization_code', 'code' => $p['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $client->clientId];
        $bad = $this->call('POST', '/token', [], $form + ['code_verifier' => str_repeat('x', 50)]);
        self::assertSame(400, $bad->status);
        self::assertSame('invalid_grant', $this->json($bad)['error']);

        $auth2 = $this->call('GET', '/authorize', [
            'client_id' => $client->clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid profile email offline_access',
            'state' => 'st-2', 'nonce' => 'nn-456', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], [], [], $cookies);
        $code = $this->params($auth2->headers['Location'])['code'];
        $tok = $this->call('POST', '/token', [], ['code' => $code, 'code_verifier' => $verifier] + $form);
        self::assertSame(200, $tok->status, $tok->body);
        self::assertStringContainsString('no-store', $tok->headers['Cache-Control'] ?? '');
        $t = $this->json($tok);
        self::assertSame('Bearer', $t['token_type']);

        // Code kedua kali: ditolak dan family refresh dicabut
        $reuse = $this->call('POST', '/token', [], ['code' => $code, 'code_verifier' => $verifier] + $form);
        self::assertSame(400, $reuse->status);
        $t = null;

        // Ulangi alur dengan code baru (family sebelumnya sudah dicabut oleh reuse)
        $code = $this->params($this->call('GET', '/authorize', [
            'client_id' => $client->clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid profile email offline_access',
            'state' => 'st-3', 'nonce' => 'nn-456', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], [], [], $cookies)->headers['Location'])['code'];
        $t = $this->json($this->call('POST', '/token', [], ['code' => $code, 'code_verifier' => $verifier] + $form));
        self::assertArrayHasKey('refresh_token', $t);

        // ID token: tanda tangan lewat JWKS, klaim wajib, nonce, sid
        $keys = JWK::parseKeySet($jwks);
        JWT::$timestamp = $s->clock->now()->getTimestamp();
        $id = (array) JWT::decode((string) $t['id_token'], $keys);
        self::assertSame(self::ISS, $id['iss']);
        self::assertSame($client->clientId, $id['aud']);
        self::assertSame('nn-456', $id['nonce']);
        self::assertSame($user->uuid, $id['sub']);
        $at = (array) JWT::decode((string) $t['access_token'], $keys);
        self::assertSame($id['sid'], $at['sid']);
        self::assertSame($client->clientId, $at['client_id']);

        // UserInfo
        $ui = $this->call('GET', '/userinfo', [], [], ['authorization' => 'Bearer ' . $t['access_token']]);
        self::assertSame(200, $ui->status);
        self::assertSame($user->uuid, $this->json($ui)['sub']);
        self::assertSame(401, $this->call('GET', '/userinfo', [], [], ['authorization' => 'Bearer salah'])->status);

        // Refresh: rotasi, lalu pemakaian ulang token lama mencabut family
        $rf = $this->call('POST', '/token', [], ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $client->clientId]);
        self::assertSame(200, $rf->status, $rf->body);
        $t2 = $this->json($rf);
        self::assertNotSame($t['refresh_token'], $t2['refresh_token']);
        $replay = $this->call('POST', '/token', [], ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => $client->clientId]);
        self::assertSame(400, $replay->status);
        $after = $this->call('POST', '/token', [], ['grant_type' => 'refresh_token', 'refresh_token' => $t2['refresh_token'], 'client_id' => $client->clientId]);
        self::assertSame(400, $after->status, 'family dicabut setelah reuse terdeteksi');

        // Logout RP-initiated: post_logout_redirect_uri exact match, sesi dicabut
        $out = $this->call('GET', '/logout', [
            'id_token_hint' => $t['id_token'], 'post_logout_redirect_uri' => self::BYE, 'state' => 'bye-1',
        ], [], [], $cookies);
        self::assertSame(302, $out->status, $out->body);
        self::assertStringStartsWith(self::BYE, $out->headers['Location']);
        self::assertSame('bye-1', $this->params($out->headers['Location'])['state']);
        $row = $s->sessions->findByIdHash(hash('sha256', $cookie));
        self::assertNotNull($row?->revokedAt);

        // Setelah logout: authorize tanpa sesi tidak menerbitkan code
        $again = $this->call('GET', '/authorize', [
            'client_id' => $client->clientId, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid',
            'state' => 'z', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], [], [], $cookies);
        self::assertStringNotContainsString('code=', $again->headers['Location'] ?? '');
    }

    public function testRedirectUriDimodifikasiDanPkceWajib(): void
    {
        $s = $this->s;
        $creator = new ClientCreator(new ClientRepository($s->db), new ClientRedirectUriRepository($s->db), $s->hasher, $s->db, $s->clock);
        $client = $creator->create('SPA', 'public', [self::REDIRECT])['client'];
        $user = $s->users->create('ani@example.test', null, 'Ani');
        $cookie = $s->manager->start($user->id, 'password', false, new Request('POST', '/login'))->value;
        $q = ['client_id' => $client->clientId, 'response_type' => 'code', 'scope' => 'openid', 'state' => 's'];
        $challenge = rtrim(strtr(base64_encode(hash('sha256', 'v', true)), '+/', '-_'), '=');
        foreach (['https://spa.example.test/cb/', 'https://spa.example.test/cb?x=1', 'https://evil.spa.example.test/cb', 'https://spa.example.test:8443/cb'] as $bad) {
            $r = $this->call('GET', '/authorize', $q + ['redirect_uri' => $bad, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'], [], [], [SsoSessionManager::COOKIE => $cookie]);
            self::assertStringNotContainsString('code=', $r->headers['Location'] ?? '', $bad);
            self::assertStringNotContainsString('evil', $r->headers['Location'] ?? '', $bad);
        }
        // tanpa PKCE: tidak ada code
        $r = $this->call('GET', '/authorize', $q + ['redirect_uri' => self::REDIRECT], [], [], [SsoSessionManager::COOKIE => $cookie]);
        self::assertStringNotContainsString('code=', $r->headers['Location'] ?? '');
    }

    public function testRateLimitTokenPerPasanganKlienDanIp(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->call('POST', '/token', [], ['grant_type' => 'authorization_code', 'client_id' => 'cl_x', 'code' => 'c', 'redirect_uri' => 'https://x']);
        }
        self::assertSame(429, $this->call('POST', '/token', [], ['grant_type' => 'authorization_code', 'client_id' => 'cl_x'])->status);
        // IP lain tidak terdampak
        $other = $this->kernel->handle(new Request('POST', '/token', [], ['grant_type' => 'authorization_code', 'client_id' => 'cl_x'], ['user-agent' => 'x'], [], [], '203.0.113.50'));
        self::assertNotSame(429, $other->status);
    }
}
