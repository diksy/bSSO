<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Federation\FederationException;
use App\Federation\FederationState;
use App\Federation\LinkOutcome;
use App\Federation\NormalizedProfile;
use App\Http\HttpException;
use App\Support\UiSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeIdp;
use Tests\Support\FedStack;

/** SQLite in-memory + IdP/HTTP palsu (bukan MariaDB, tanpa jaringan nyata). */
final class FederationTest extends TestCase
{
    private FedStack $f;

    protected function setUp(): void
    {
        UiSession::fake();
        $this->f = new FedStack();
    }

    protected function tearDown(): void
    {
        UiSession::reset();
    }

    private function loc(\App\Http\Response $r): string
    {
        return $r->headers['Location'] ?? '';
    }

    public function testStartMenyimpanHashStateNoncePkceDanKedaluwarsa10Menit(): void
    {
        [$res, $state, $nonce] = $this->f->start('fakeoidc', ['return' => '/authorize?x=1']);
        self::assertSame(302, $res->status);
        $url = $this->loc($res);
        self::assertStringStartsWith(FakeIdp::AUTHORIZE . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('code', $q['response_type']);
        self::assertSame('cid', $q['client_id']);
        self::assertSame('https://sso.example.test/federation/fakeoidc/callback', $q['redirect_uri']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertNotSame('', $q['code_challenge']);
        self::assertSame(64, strlen($state));
        self::assertNotSame('', $nonce);

        $row = $this->f->s->db->pdo()->query('SELECT * FROM federation_states')->fetch();
        self::assertSame(hash('sha256', $state), $row['state_hash']);
        self::assertNotSame($state, $row['state_hash'], 'state mentah tidak disimpan');
        self::assertSame($nonce, $row['nonce']);
        self::assertSame('2026-03-01 10:10:00', $row['expires_at']);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $row['code_verifier'], true)), '+/', '-_'), '='), $q['code_challenge']);
        self::assertStringContainsString('/authorize?x=1', $row['pending_request']);
    }

    public function testStartProviderNonaktifDanSlugAsingDitolak(): void
    {
        $this->f->addProvider('mati', 'oidc', 'FakeOidcDriver', true, false);
        $this->f->addProvider('evil', 'oidc', 'Evil\\Thing', true);
        foreach (['mati', 'evil', 'tidakada', '../x'] as $slug) {
            try {
                $this->f->fed->start($this->f->req('GET', '/x', $slug));
                self::fail("harus 404: $slug");
            } catch (HttpException $e) {
                self::assertSame(404, $e->status);
            }
        }
    }

    public function testDaftarPutihDriver(): void
    {
        $this->f->addProvider('evil', 'oidc', 'Evil\\Thing', true);
        $this->expectException(FederationException::class);
        $this->f->registry->get('evil');
    }

    public function testCabang5UserBaruDariEmailTerverifikasiProviderTepercaya(): void
    {
        $res = $this->f->oidcLogin('fakeoidc');
        self::assertSame(303, $res->status);
        self::assertSame('/account', $this->loc($res));
        $cookie = $this->f->cookieOf($res);
        self::assertNotNull($cookie);
        $user = $this->f->s->users->findByEmail('ani@example.test');
        self::assertNotNull($user);
        self::assertNull($user->passwordHash);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertSame('active', $user->status);
        self::assertCount(1, $this->f->identities->listForUser($user->id));
        $session = $this->f->s->sessions->findByIdHash(hash('sha256', (string) $cookie));
        self::assertSame('fakeoidc', $session?->authMethod);
        // secret/token tak pernah masuk raw_profile tersimpan
        $raw = (string) $this->f->s->db->pdo()->query('SELECT raw_profile FROM user_identities')->fetchColumn();
        self::assertStringContainsString('idp-user-1', $raw);
    }

    public function testCabang1IdentitasSudahAdaLogin(): void
    {
        $this->f->oidcLogin('fakeoidc');
        $res = $this->f->oidcLogin('fakeoidc', ['email' => 'ganti@example.test', 'email_verified' => false]);
        self::assertNotNull($this->f->cookieOf($res));
        self::assertSame(1, $this->f->count('users'));
        self::assertSame(1, $this->f->count('user_identities'));
    }

    public function testCabang3TautOtomatisHanyaBilaTepercayaDanTerverifikasi(): void
    {
        $user = $this->f->makeUser();
        $res = $this->f->oidcLogin('fakeoidc');
        self::assertNotNull($this->f->cookieOf($res));
        self::assertCount(1, $this->f->identities->listForUser($user->id));
        self::assertSame(1, $this->f->count('users'));
    }

    public function testCabang4ProviderTidakTepercayaMintaKonfirmasiTanpaTautan(): void
    {
        $user = $this->f->makeUser();
        $res = $this->f->oidcLogin('untrusted');
        self::assertSame('/federation/untrusted/confirm-link', $this->loc($res));
        self::assertNull($this->f->cookieOf($res), 'tanpa sesi sebelum konfirmasi');
        self::assertSame([], $this->f->identities->listForUser($user->id));
    }

    public function testTakeoverEmailTakTerverifikasiDiProviderTepercayaTidakMenautkan(): void
    {
        $victim = $this->f->makeUser();
        $res = $this->f->oidcLogin('fakeoidc', ['email_verified' => false]);
        self::assertSame('/federation/fakeoidc/confirm-link', $this->loc($res));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame([], $this->f->identities->listForUser($victim->id));
        self::assertSame(0, $this->f->count('sso_sessions'));
    }

    public function testKonfirmasiButuhPasswordBenarLaluMenautkan(): void
    {
        $user = $this->f->makeUser();
        $this->f->oidcLogin('untrusted');
        $form = $this->f->fed->confirmForm($this->f->req('GET', '/x', 'untrusted'));
        self::assertSame(200, $form->status);
        self::assertStringContainsString('ani@example.test', $form->body);

        $bad = $this->f->fed->confirmSubmit($this->f->req('POST', '/x', 'untrusted', [], ['password' => 'salah-salah-salah']));
        self::assertSame(401, $bad->status);
        self::assertSame([], $this->f->identities->listForUser($user->id));
        self::assertSame(0, $this->f->count('sso_sessions'));

        $ok = $this->f->fed->confirmSubmit($this->f->req('POST', '/x', 'untrusted', [], ['password' => 'kata-sandi-panjang-1']));
        self::assertSame(303, $ok->status);
        self::assertNotNull($this->f->cookieOf($ok));
        self::assertCount(1, $this->f->identities->listForUser($user->id));
        self::assertSame('/login', $this->loc($this->f->fed->confirmForm($this->f->req('GET', '/x', 'untrusted'))), 'alur sekali pakai');
    }

    public function testKonfirmasiDibatasiLaju(): void
    {
        $this->f->makeUser();
        $this->f->oidcLogin('untrusted');
        for ($i = 0; $i < 5; $i++) {
            $this->f->fed->confirmSubmit($this->f->req('POST', '/x', 'untrusted', [], ['password' => 'salah-salah-salah']));
        }
        $res = $this->f->fed->confirmSubmit($this->f->req('POST', '/x', 'untrusted', [], ['password' => 'kata-sandi-panjang-1']));
        self::assertSame(429, $res->status);
        self::assertSame(0, $this->f->count('user_identities'));
    }

    public function testAkunPraDaftarTakTerverifikasiDiambilAlihPemilikSah(): void
    {
        // Penyerang mendaftar dengan email korban (pending, password dikenal penyerang).
        $pre = $this->f->makeUser('ani@example.test', 'password-penyerang-1', 'pending', false);
        $res = $this->f->oidcLogin('fakeoidc');
        self::assertNotNull($this->f->cookieOf($res));
        $user = $this->f->s->users->findById($pre->id);
        self::assertNull($user?->passwordHash, 'password penyerang dibuang');
        self::assertSame('active', $user?->status);
        self::assertNotNull($user->emailVerifiedAt);
    }

    public function testUserSuspendedTidakBisaMasuk(): void
    {
        $this->f->makeUser('ani@example.test', 'kata-sandi-panjang-1', 'suspended');
        $res = $this->f->oidcLogin('fakeoidc');
        self::assertNull($this->f->cookieOf($res));
        self::assertSame('/login', $this->loc($res));
        self::assertSame(0, $this->f->count('sso_sessions'));
    }

    /** @return array{\App\Repository\Record\User, string} */
    private function mfaUser(): array
    {
        $u = $this->f->makeUser();
        $secret = $this->f->s->totp->generateSecret();
        $codes = $this->f->s->backup->generate();
        $this->f->s->users->enableMfa($u->id, $this->f->s->crypto->encrypt($secret), $codes['hashes'], 1, $this->f->s->clock->now());

        return [$this->f->s->users->findById($u->id) ?? $u, $secret];
    }

    private function totpNow(string $secret): string
    {
        return $this->f->s->totp->code(\App\Security\Totp::base32Decode($secret), intdiv($this->f->s->clock->now()->getTimestamp(), 30));
    }

    public function testUserBerMfaTetapMelewatiTantanganMfaSetelahFederasi(): void
    {
        [$user, $secret] = $this->mfaUser();
        $res = $this->f->oidcLogin('fakeoidc'); // cabang 3 (tautan otomatis)
        self::assertSame('/login/mfa', $this->loc($res));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame(0, $this->f->count('sso_sessions'), 'tak ada sesi sebelum MFA');
        $pre = UiSession::get('pre_mfa');
        self::assertSame($user->id, $pre['user_id']);

        // kode salah -> tetap tanpa sesi
        $bad = $this->f->mfa->submit($this->f->req('POST', '/login/mfa', 'x', [], ['code' => '000000']));
        self::assertSame(401, $bad->status);
        self::assertSame(0, $this->f->count('sso_sessions'));

        $ok = $this->f->mfa->submit($this->f->req('POST', '/login/mfa', 'x', [], ['code' => $this->totpNow($secret)]));
        $cookie = $this->f->cookieOf($ok);
        self::assertNotNull($cookie);
        $session = $this->f->s->sessions->findByIdHash(hash('sha256', (string) $cookie));
        self::assertNotNull($session?->mfaPassedAt);
        self::assertSame('fakeoidc', $session->authMethod);
    }

    public function testIdentitasLamaUserBerMfaJugaMeminta(): void
    {
        [$user] = $this->mfaUser();
        $this->f->identities->create($user->id, 1, 'idp-user-1', null, false, null, $this->f->s->clock->now());
        $res = $this->f->oidcLogin('fakeoidc');
        self::assertSame('/login/mfa', $this->loc($res));
        self::assertSame(0, $this->f->count('sso_sessions'));
    }

    public function testKonfirmasiUserBerMfaMenautkanHanyaSetelahMfaLulus(): void
    {
        [$user, $secret] = $this->mfaUser();
        $this->f->oidcLogin('untrusted');
        $res = $this->f->fed->confirmSubmit($this->f->req('POST', '/x', 'untrusted', [], ['password' => 'kata-sandi-panjang-1']));
        self::assertSame('/login/mfa', $this->loc($res));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame([], $this->f->identities->listForUser($user->id), 'belum tertaut sebelum TOTP');

        $this->f->mfa->submit($this->f->req('POST', '/login/mfa', 'x', [], ['code' => $this->totpNow($secret)]));
        self::assertCount(1, $this->f->identities->listForUser($user->id));
    }

    // ---- state ----

    public function testStateSalahDitolak(): void
    {
        $this->f->start('fakeoidc');
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => str_repeat('a', 64), 'code' => 'c']));
        self::assertSame('/login', $this->loc($res));
        self::assertSame(0, $this->f->count('sso_sessions'));
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => 'pendek', 'code' => 'c']));
        self::assertSame('/login', $this->loc($res));
        self::assertSame([], $this->f->idp->tokenRequests, 'provider tak dipanggil');
    }

    public function testStateKedaluwarsaDitolak(): void
    {
        [, $state, $nonce] = $this->f->start('fakeoidc');
        $this->f->idp->idToken = $this->f->idp->makeIdToken($nonce);
        $this->f->s->clock->advance('+601 seconds');
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'code' => 'c']));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame(0, $this->f->count('sso_sessions'));
        self::assertSame([], $this->f->idp->tokenRequests);
    }

    public function testReplayStateDitolak(): void
    {
        [, $state, $nonce] = $this->f->start('fakeoidc');
        $this->f->idp->idToken = $this->f->idp->makeIdToken($nonce);
        $req = $this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'code' => 'c']);
        self::assertNotNull($this->f->cookieOf($this->f->fed->callback($req)));
        // ulang persis sama (sesi UI mungkin masih ada, tetapi state sudah habis)
        UiSession::set('fed_states', [hash('sha256', $state)]);
        $second = $this->f->fed->callback($req);
        self::assertNull($this->f->cookieOf($second));
        self::assertSame(1, $this->f->count('sso_sessions'));
    }

    public function testStateTidakTerikatBrowserDitolak(): void
    {
        [, $state, $nonce] = $this->f->start('fakeoidc');
        $this->f->idp->idToken = $this->f->idp->makeIdToken($nonce);
        UiSession::fake(); // browser lain / korban: sesi UI tanpa pengikat
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'code' => 'c']));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame(0, $this->f->count('sso_sessions'));
        self::assertSame([], $this->f->idp->tokenRequests);
    }

    public function testStateMilikProviderLainDitolak(): void
    {
        [, $state, $nonce] = $this->f->start('fakeoidc');
        $this->f->idp->idToken = $this->f->idp->makeIdToken($nonce);
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'untrusted', ['state' => $state, 'code' => 'c']));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame(0, $this->f->count('sso_sessions'));
    }

    public function testErrorDariProviderDitolak(): void
    {
        [, $state] = $this->f->start('fakeoidc');
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'error' => 'access_denied']));
        self::assertSame('/login', $this->loc($res));
        self::assertSame(0, $this->f->count('sso_sessions'));
    }

    // ---- id_token ----

    /** @param array<string,mixed> $override */
    private function assertRejected(array $override, ?string $nonce = null): void
    {
        $before = $this->f->count('users');
        $res = $this->f->oidcLogin('fakeoidc', $override, $nonce);
        self::assertNull($this->f->cookieOf($res), 'sesi tidak boleh dibuat: ' . json_encode($override));
        self::assertSame($before, $this->f->count('users'));
        self::assertSame(0, $this->f->count('user_identities'));
    }

    public function testNonceSalahDitolak(): void
    {
        $this->assertRejected([], 'nonce-penyerang');
    }

    public function testNonceHilangDitolak(): void
    {
        $this->assertRejected(['nonce' => null]);
    }

    public function testIssSalahDitolak(): void
    {
        $this->assertRejected(['iss' => 'https://evil.example.test']);
    }

    public function testAudSalahDitolak(): void
    {
        $this->assertRejected(['aud' => 'klien-lain']);
        $this->assertRejected(['aud' => ['cid', 'lain'], 'azp' => 'lain']);
    }

    public function testTokenKedaluwarsaDitolak(): void
    {
        $this->assertRejected(['exp' => 1772359200 - 3600]);
        $this->assertRejected(['exp' => null]);
    }

    public function testAlgNoneDitolak(): void
    {
        [, $state, $nonce] = $this->f->start('fakeoidc');
        $this->f->idp->idToken = FakeIdp::unsigned([
            'iss' => FakeIdp::ISS, 'aud' => 'cid', 'sub' => 'x', 'exp' => 1772359200 + 600, 'nonce' => $nonce,
            'email' => 'ani@example.test', 'email_verified' => true,
        ]);
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'code' => 'c']));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame(0, $this->f->count('users'));
    }

    public function testAlgHs256DenganKunciPublikDitolak(): void
    {
        [, $state, $nonce] = $this->f->start('fakeoidc');
        $this->f->idp->idToken = \Firebase\JWT\JWT::encode([
            'iss' => FakeIdp::ISS, 'aud' => 'cid', 'sub' => 'x', 'exp' => 1772359200 + 600, 'nonce' => $nonce,
        ], str_repeat('s', 32), 'HS256', 'k1');
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'code' => 'c']));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame(0, $this->f->count('users'));
    }

    public function testTandaTanganKunciLainDitolak(): void
    {
        $other = new FakeIdp('b'); // kunci berbeda, kid sama 'k1'
        [, $state, $nonce] = $this->f->start('fakeoidc');
        $this->f->idp->idToken = $other->makeIdToken($nonce);
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'code' => 'c']));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame(0, $this->f->count('users'));
    }

    public function testKidTakDikenalMengambilUlangJwksSekaliLaluBerhasil(): void
    {
        // Warm cache JWKS dengan login pertama.
        $this->f->oidcLogin('fakeoidc');
        self::assertSame(1, $this->f->idp->jwksFetches);
        // IdP merotasi kunci dan memublikasikan JWKS baru.
        $this->f->idp->rotateTo('k2', 'b', true);
        $res = $this->f->oidcLogin('fakeoidc');
        self::assertNotNull($this->f->cookieOf($res));
        self::assertSame(2, $this->f->idp->jwksFetches, 'refetch tepat sekali');
    }

    public function testKidTetapTakDikenalHanyaSatuKaliRefetch(): void
    {
        $this->f->oidcLogin('fakeoidc');
        $this->f->idp->rotateTo('k3', 'b', false); // tidak dipublikasikan
        $before = $this->f->idp->jwksFetches;
        $res = $this->f->oidcLogin('fakeoidc');
        self::assertSame(1, $this->f->count('sso_sessions'));
        self::assertNull($this->f->cookieOf($res));
        self::assertSame($before + 1, $this->f->idp->jwksFetches);
    }

    public function testSecretKlienDienkripsiDanDikirimTerdekripsiTanpaMasukLog(): void
    {
        $enc = (string) $this->f->s->db->pdo()->query("SELECT client_secret_enc FROM identity_providers WHERE slug='fakeoidc'")->fetchColumn();
        self::assertStringNotContainsString('shh-secret', $enc);
        $this->f->oidcLogin('fakeoidc');
        $req = $this->f->idp->tokenRequests[0]['body'];
        self::assertSame('shh-secret', $req['client_secret']);
        self::assertSame('authorization_code', $req['grant_type']);
        self::assertNotSame('', $req['code_verifier']);
        self::assertSame('https://sso.example.test/federation/fakeoidc/callback', $req['redirect_uri']);
        $audit = (string) json_encode($this->f->s->db->pdo()->query('SELECT * FROM audit_logs')->fetchAll());
        self::assertStringNotContainsString('shh-secret', $audit);
        self::assertStringNotContainsString('code-1', $audit);
        self::assertStringNotContainsString('jangan-simpan', (string) $this->f->s->db->pdo()->query('SELECT raw_profile FROM user_identities')->fetchColumn());
    }

    public function testDiscoveryDiCachePutihTanpaPanggilanUlang(): void
    {
        $this->f->oidcLogin('fakeoidc');
        $this->f->oidcLogin('fakeoidc');
        self::assertSame(1, $this->f->idp->discoveryFetches);
    }

    public function testSsrfDiscoveryKeAlamatInternalDiblokir(): void
    {
        $f = new FedStack(['internal.example.test' => '10.0.0.5']);
        $f->addProvider('internal', 'oidc', 'FakeOidcDriver', true, true, 'https://internal.example.test/.well-known/openid-configuration');
        $res = $f->fed->start($f->req('GET', '/x', 'internal'));
        self::assertSame('/login', $res->headers['Location'] ?? '');
        self::assertSame([], $f->idp->urls, 'permintaan tidak pernah mencapai transport');
        self::assertSame(0, $f->count('federation_states'));
    }

    // ---- OAuth2 / lengkapi email ----

    /** @param array<string,mixed> $userinfo */
    private function oauth2Login(array $userinfo): \App\Http\Response
    {
        [, $state] = $this->f->start('fakeoauth');
        $this->f->idp->userinfo = $userinfo;
        $this->f->idp->idToken = null;

        return $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoauth', ['state' => $state, 'code' => 'c']));
    }

    public function testOauth2TanpaEmailMintaLengkapiEmailDanTidakMembuatAkunDulu(): void
    {
        $res = $this->oauth2Login(['id' => '777', 'name' => 'Xer']);
        self::assertSame('/federation/fakeoauth/complete-email', $this->loc($res));
        self::assertSame(0, $this->f->count('users'));
        self::assertSame(0, $this->f->count('user_identities'));
        self::assertSame(1, count($this->f->idp->tokenRequests));
        self::assertArrayNotHasKey('nonce', $this->f->idp->tokenRequests[0]['body']);

        self::assertSame(200, $this->f->fed->emailForm($this->f->req('GET', '/x', 'fakeoauth'))->status);
        $bad = $this->f->fed->emailSubmit($this->f->req('POST', '/x', 'fakeoauth', [], ['email' => 'bukan-email']));
        self::assertSame(422, $bad->status);

        $ok = $this->f->fed->emailSubmit($this->f->req('POST', '/x', 'fakeoauth', [], ['email' => 'Baru@Example.test']));
        self::assertSame(200, $ok->status);
        self::assertSame(0, $this->f->count('users'), 'akun belum dibuat sebelum email terverifikasi');
        $token = $this->f->s->mailer->lastToken();
        self::assertSame(64, strlen($token));
        self::assertSame('baru@example.test', $this->f->s->mailer->sent[0]['to']);

        $verify = $this->f->fed->verifyEmail($this->f->req('GET', '/x', 'fakeoauth', ['token' => $token]));
        self::assertSame('/login', $this->loc($verify));
        self::assertNull($this->f->cookieOf($verify), 'tidak login otomatis dari tautan email');
        $user = $this->f->s->users->findByEmail('baru@example.test');
        self::assertNotNull($user);
        self::assertSame('active', $user->status);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertNull($user->passwordHash);
        self::assertCount(1, $this->f->identities->listForUser($user->id));

        // tautan sekali pakai
        $again = $this->f->fed->verifyEmail($this->f->req('GET', '/x', 'fakeoauth', ['token' => $token]));
        self::assertSame('/login', $this->loc($again));
        self::assertSame(1, $this->f->count('users'));

        // login berikutnya lewat provider (cabang 1)
        $login = $this->oauth2Login(['id' => '777']);
        self::assertNotNull($this->f->cookieOf($login));
    }

    public function testLengkapiEmailMilikAkunLainTidakMenautkanDanTidakMembocorkan(): void
    {
        $victim = $this->f->makeUser();
        $this->oauth2Login(['id' => '888']);
        $res = $this->f->fed->emailSubmit($this->f->req('POST', '/x', 'fakeoauth', [], ['email' => 'ani@example.test']));
        self::assertSame(200, $res->status);
        self::assertStringContainsString('ani@example.test', $res->body);
        self::assertSame(0, $this->f->count('federation_states'), 'tidak ada token verifikasi untuk email yang sudah dipakai');
        self::assertSame([], $this->f->identities->listForUser($victim->id));
        self::assertStringNotContainsString('token=', $this->f->s->mailer->sent[0]['text']);
    }

    public function testTokenVerifikasiEmailPalsuAtauStateOauthDitolak(): void
    {
        $this->oauth2Login(['id' => '999']);
        // state OAuth biasa tidak bisa dipakai sebagai token verifikasi email
        [, $state] = $this->f->start('fakeoauth');
        $res = $this->f->fed->verifyEmail($this->f->req('GET', '/x', 'fakeoauth', ['token' => $state]));
        self::assertSame('/login', $this->loc($res));
        self::assertSame(0, $this->f->count('users'));
        $this->f->fed->verifyEmail($this->f->req('GET', '/x', 'fakeoauth', ['token' => str_repeat('0', 64)]));
        self::assertSame(0, $this->f->count('user_identities'));
    }

    public function testTokenVerifikasiEmailKedaluwarsa(): void
    {
        $this->oauth2Login(['id' => '999']);
        $this->f->fed->emailSubmit($this->f->req('POST', '/x', 'fakeoauth', [], ['email' => 'baru@example.test']));
        $token = $this->f->s->mailer->lastToken();
        $this->f->s->clock->advance('+25 hours');
        $this->f->fed->verifyEmail($this->f->req('GET', '/x', 'fakeoauth', ['token' => $token]));
        self::assertSame(0, $this->f->count('users'));
    }

    public function testOauth2EmailTakTerverifikasiDiProviderTakTepercayaTetapMinta(): void
    {
        $this->f->makeUser();
        $res = $this->oauth2Login(['id' => '5', 'email' => 'ani@example.test', 'verified' => false]);
        self::assertSame('/federation/fakeoauth/confirm-link', $this->loc($res));
        self::assertNull($this->f->cookieOf($res));
    }

    // ---- form_post (Apple) ----

    public function testFormPostHanyaUntukDriverFormPost(): void
    {
        try {
            $this->f->fed->formPostCallback($this->f->req('POST', '/x', 'fakeoidc', [], ['state' => str_repeat('a', 64), 'code' => 'c']));
            self::fail('harus 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
        }
    }

    public function testFormPostMenggabungUserLalu303KeGetCallbackTanpaParamLain(): void
    {
        $this->f->addProvider('apple', 'oidc', 'AppleDriver', true);
        [, $state] = $this->f->start('apple');
        $res = $this->f->fed->formPostCallback($this->f->req('POST', '/x', 'apple', [], [
            'state' => $state, 'code' => 'c-1', 'id_token' => 'diabaikan', 'foo' => 'bar',
            'user' => json_encode(['name' => ['firstName' => 'Ani', 'lastName' => "Apple\x00"], 'email' => 'x@y.test']),
        ]));
        self::assertSame(303, $res->status);
        self::assertSame("/federation/apple/callback?state=$state&code=c-1", $this->loc($res));
        $st = $this->f->states->find(hash('sha256', $state));
        self::assertSame(['name' => ['firstName' => 'Ani', 'lastName' => 'Apple']], $st?->pendingRequest['user']);
        self::assertSame(1, $this->f->count('federation_states'), 'tidak dikonsumsi');

        // user tak dapat menimpa kunci internal ('_') maupun return
        self::assertTrue($this->f->states->mergePending(hash('sha256', $state), ['_kind' => 'email_verify']));
        self::assertArrayNotHasKey('_kind', $this->f->states->find(hash('sha256', $state))->pendingRequest ?? []);
    }

    public function testFormPostStateTakDikenalTidakMembuatApaPun(): void
    {
        $this->f->addProvider('apple', 'oidc', 'AppleDriver', true);
        $res = $this->f->fed->formPostCallback($this->f->req('POST', '/x', 'apple', [], [
            'state' => str_repeat('b', 64), 'code' => 'c', 'user' => json_encode(['name' => ['firstName' => 'A']]),
        ]));
        self::assertSame(303, $res->status);
        self::assertSame(0, $this->f->count('federation_states'));
        $this->f->s->clock->advance('+11 minutes');
        [, $state] = $this->f->start('apple');
        $this->f->s->clock->advance('+11 minutes');
        self::assertFalse($this->f->states->mergePending(hash('sha256', $state), ['user' => ['x' => 1]]));
    }

    public function testFormPostError(): void
    {
        $this->f->addProvider('apple', 'oidc', 'AppleDriver', true);
        $res = $this->f->fed->formPostCallback($this->f->req('POST', '/x', 'apple', [], ['state' => str_repeat('a', 64), 'error' => 'user_cancelled_authorize']));
        self::assertStringEndsWith('&error=user_cancelled_authorize', $this->loc($res));
        try {
            $this->f->fed->formPostCallback($this->f->req('POST', '/x', 'apple', [], ['state' => 'bad']));
            self::fail('400');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
        }
    }

    // ---- akun tertaut (F7) ----

    /** @return array{\App\Repository\Record\User, string} */
    private function loggedIn(string $email = 'ani@example.test', ?string $pw = 'kata-sandi-panjang-1'): array
    {
        $u = $this->f->makeUser($email, $pw);
        $cookie = $this->f->s->manager->start($u->id, 'password', false, $this->f->req('GET', '/x', 'x'))->value;

        return [$u, $cookie];
    }

    public function testMenautkanDariAkunButuhNiatPost(): void
    {
        [$u, $cookie] = $this->loggedIn();
        // tanpa niat (mis. link GET dibuat penyerang) -> ditolak, tidak ada state
        [$res] = $this->f->start('fakeoidc', ['link' => '1'], $cookie);
        self::assertSame('/account/linked', $this->loc($res));
        self::assertSame(0, $this->f->count('federation_states'));

        $intent = $this->f->linked->link($this->f->req('POST', '/x', 'fakeoidc', [], [], $cookie)->withAttribute('user', $u));
        self::assertSame('/federation/fakeoidc/start?link=1', $this->loc($intent));
        $res = $this->f->oidcLogin('fakeoidc', ['sub' => 'ext-1', 'email' => 'lain@example.test'], null, $cookie, ['link' => '1']);
        self::assertSame('/account/linked', $this->loc($res));
        self::assertNull($this->f->cookieOf($res), 'tidak ganti sesi saat menautkan');
        $ids = $this->f->identities->listForUser($u->id);
        self::assertCount(1, $ids);
        self::assertSame('ext-1', $ids[0]->providerUserId);
        self::assertSame(1, $this->f->count('users'));
    }

    public function testNiatMenautkanSekaliPakaiDanTerikatUser(): void
    {
        [$u, $cookie] = $this->loggedIn();
        $this->f->linked->link($this->f->req('POST', '/x', 'fakeoidc', [], [], $cookie)->withAttribute('user', $u));
        $this->f->start('fakeoidc', ['link' => '1'], $cookie);
        [$res] = $this->f->start('fakeoidc', ['link' => '1'], $cookie);
        self::assertSame('/account/linked', $this->loc($res), 'niat sudah terpakai');
    }

    public function testCallbackTautanDenganSesiUserLainDitolak(): void
    {
        [$u, $cookie] = $this->loggedIn();
        $this->f->linked->link($this->f->req('POST', '/x', 'fakeoidc', [], [], $cookie)->withAttribute('user', $u));
        [, $state, $nonce] = $this->f->start('fakeoidc', ['link' => '1'], $cookie);
        $this->f->idp->idToken = $this->f->idp->makeIdToken($nonce);
        $other = $this->f->makeUser('lain@example.test');
        $otherCookie = $this->f->s->manager->start($other->id, 'password', false, $this->f->req('GET', '/x', 'x'))->value;
        $res = $this->f->fed->callback($this->f->req('GET', '/x', 'fakeoidc', ['state' => $state, 'code' => 'c'], [], $otherCookie));
        self::assertSame('/login', $this->loc($res));
        self::assertSame(0, $this->f->count('user_identities'));
        self::assertSame([], $this->f->idp->tokenRequests);
    }

    public function testIdentitasMilikAkunLainTidakBisaDitautkanDanTidakMenukarSesi(): void
    {
        $this->f->oidcLogin('fakeoidc'); // ani (via federasi) memiliki idp-user-1
        [$u, $cookie] = $this->loggedIn('lain@example.test');
        $this->f->linked->link($this->f->req('POST', '/x', 'fakeoidc', [], [], $cookie)->withAttribute('user', $u));
        $res = $this->f->oidcLogin('fakeoidc', [], null, $cookie, ['link' => '1']);
        self::assertNull($this->f->cookieOf($res));
        self::assertSame('/account/linked', $this->loc($res));
        self::assertSame([], $this->f->identities->listForUser($u->id));
    }

    public function testLinkerLimaCabangLangsung(): void
    {
        $f = $this->f;
        $p = $f->providers->findBySlug('fakeoidc');
        $st = new FederationState($p->id, null, null, [], null);
        $profile = new NormalizedProfile('u1', 'new@example.test', true, 'N', null, []);
        self::assertSame(LinkOutcome::Created, $f->linker->resolve($profile, $p, $st)->outcome);
        self::assertSame(LinkOutcome::Login, $f->linker->resolve($profile, $p, $st)->outcome);
        $other = new NormalizedProfile('u2', 'new@example.test', true, 'N', null, []);
        self::assertSame(LinkOutcome::AutoLinked, $f->linker->resolve($other, $p, $st)->outcome);
        $un = $f->providers->findBySlug('untrusted');
        $third = new NormalizedProfile('u3', 'new@example.test', true, 'N', null, []);
        self::assertSame(LinkOutcome::NeedsConfirmation, $f->linker->resolve($third, $un, new FederationState($un->id, null, null, [], null))->outcome);
        $none = new NormalizedProfile('u4', null, false, null, null, []);
        self::assertSame(LinkOutcome::NeedsEmail, $f->linker->resolve($none, $p, $st)->outcome);
        $user = $f->s->users->findByEmail('new@example.test');
        $acc = new NormalizedProfile('u5', null, false, null, null, []);
        self::assertSame(LinkOutcome::LinkedFromAccount, $f->linker->resolve($acc, $un, new FederationState($un->id, null, null, [], $user->id))->outcome);
    }

    public function testUnlinkTidakBolehMeninggalkanAkunTanpaMetodeLogin(): void
    {
        // akun hanya-federasi dengan satu tautan
        $this->f->oidcLogin('fakeoidc');
        $u = $this->f->s->users->findByEmail('ani@example.test');
        $cookie = $this->f->s->manager->start($u->id, 'fakeoidc', false, $this->f->req('GET', '/x', 'x'))->value;
        $req = $this->f->req('POST', '/x', 'fakeoidc', [], [], $cookie)->withAttribute('user', $u);
        $res = $this->f->linked->unlink($req);
        self::assertSame('/account/linked', $this->loc($res));
        self::assertCount(1, $this->f->identities->listForUser($u->id), 'tautan terakhir tidak diputus');
        self::assertSame('err', \App\Support\Flash::pull()[0]['type']);

        // tautan kedua -> boleh memutus salah satunya; yang tersisa tidak boleh diputus
        $this->f->identities->create($u->id, 2, 'u-2', null, false, null, $this->f->s->clock->now());
        $this->f->linked->unlink($req);
        self::assertCount(1, $this->f->identities->listForUser($u->id));
        $this->f->linked->unlink($req->withAttribute('slug', 'untrusted'));
        self::assertCount(1, $this->f->identities->listForUser($u->id));
    }

    public function testUnlinkBolehBilaAdaPassword(): void
    {
        [$u, $cookie] = $this->loggedIn();
        $this->f->identities->create($u->id, 1, 'u-1', null, false, null, $this->f->s->clock->now());
        $req = $this->f->req('POST', '/x', 'fakeoidc', [], [], $cookie)->withAttribute('user', $u);
        $this->f->linked->unlink($req);
        self::assertSame([], $this->f->identities->listForUser($u->id));
        // memutus yang tidak tertaut -> tidak error, tidak memengaruhi akun lain
        $this->f->linked->unlink($req);
    }

    public function testUnlinkTidakMenyentuhIdentitasUserLain(): void
    {
        $other = $this->f->makeUser('lain@example.test');
        $this->f->identities->create($other->id, 1, 'u-lain', null, false, null, $this->f->s->clock->now());
        [$u, $cookie] = $this->loggedIn();
        $this->f->linked->unlink($this->f->req('POST', '/x', 'fakeoidc', [], [], $cookie)->withAttribute('user', $u));
        self::assertCount(1, $this->f->identities->listForUser($other->id));
    }

    public function testHalamanAkunTertautMenampilkanStatus(): void
    {
        [$u, $cookie] = $this->loggedIn();
        $this->f->identities->create($u->id, 1, 'u-1', null, false, null, $this->f->s->clock->now());
        $session = $this->f->s->sessions->findByIdHash(hash('sha256', $cookie));
        $res = $this->f->linked->show($this->f->req('GET', '/x', 'x', [], [], $cookie)->withAttribute('user', $u)->withAttribute('sso_session', $session));
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Tertaut', $res->body);
        self::assertStringContainsString('/account/linked/fakeoidc/unlink', $res->body);
        self::assertStringContainsString('/account/linked/untrusted/link', $res->body);
        self::assertStringNotContainsString('start?link=1', $res->body);
    }
}
