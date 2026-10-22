<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\Account\AccountPage;
use App\Controller\Account\MfaSetupController;
use App\Controller\LoginController;
use App\Controller\MfaController;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Record\User;
use App\Security\Totp;
use App\Support\Logger;
use App\Support\UiSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** SQLite in-memory (bukan MariaDB): menguji logika MFA, bukan dialek SQL produksi. */
final class MfaTest extends TestCase
{
    private const PW = 'kata-sandi-panjang-1';
    private Stack $s;

    protected function setUp(): void
    {
        UiSession::fake();
        $this->s = new Stack();
    }

    protected function tearDown(): void
    {
        UiSession::reset();
    }

    /** @param array<string,mixed> $body */
    private function post(string $path, array $body, ?User $user = null, ?string $cookie = null): Request
    {
        $req = new Request('POST', $path, [], $body, ['user-agent' => 'PHPUnit'], $cookie === null ? [] : ['__Host-bsso' => $cookie], [], '198.51.100.7');
        if ($user !== null) {
            $session = $this->s->sessions->findByIdHash(hash('sha256', (string) $cookie));
            $req = $req->withAttribute('user', $user)->withAttribute('sso_session', $session);
        }

        return $req;
    }

    private function mfaController(): MfaController
    {
        $s = $this->s;

        return new MfaController($s->view, $s->users, $s->totp, $s->crypto, $s->backup, $s->limiter, $s->manager, $s->audit, $s->clock);
    }

    private function setupController(): MfaSetupController
    {
        $s = $this->s;
        $page = new AccountPage($s->view, $s->roles);

        return new MfaSetupController($page, $s->users, $s->totp, $s->crypto, $s->backup, $s->hasher, $s->limiter, $s->manager, $s->audit, $s->clock, $s->db);
    }

    private function makeUser(): User
    {
        $u = $this->s->users->create('ani@example.test', $this->s->hasher->hash(self::PW), 'Ani');
        $this->s->users->markEmailVerified($u->id, $this->s->clock->now());

        return $u;
    }

    private function codeNow(string $secret, int $offset = 0): string
    {
        $step = intdiv($this->s->clock->now()->getTimestamp(), 30) + $offset;

        return $this->s->totp->code(Totp::base32Decode($secret), $step);
    }

    /** @return array{User, string, list<string>} user ber-MFA, secret, kode cadangan */
    private function userWithMfa(): array
    {
        $u = $this->makeUser();
        $secret = $this->s->totp->generateSecret();
        $codes = $this->s->backup->generate();
        $this->s->users->enableMfa($u->id, $this->s->crypto->encrypt($secret), $codes['hashes'], 1, $this->s->clock->now());

        return [$this->s->users->findById($u->id) ?? $u, $secret, $codes['plain']];
    }

    private function loginPassword(): Response
    {
        $s = $this->s;
        $c = new LoginController($s->view, $s->users, $s->hasher, $s->limiter, $s->manager, $s->audit, $s->clock);

        return $c->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
    }

    private function sessionCount(): int
    {
        return (int) $this->s->db->pdo()->query('SELECT COUNT(*) FROM sso_sessions')->fetchColumn();
    }

    public function testVektorRfc6238(): void
    {
        $totp = new Totp(new \App\Support\FrozenClock('1970-01-01 00:00:59'));
        $secret = Totp::base32Encode('12345678901234567890');
        self::assertSame(1, $totp->verify($secret, '287082', null));
        self::assertNull($totp->verify($secret, '287082', 1), 'replay step sama ditolak');
        self::assertNull($totp->verify($secret, '000000', null));
        self::assertNull($totp->verify($secret, '28708', null));
        self::assertNull($totp->verify($secret, 'abcdef', null));
    }

    public function testJendelaPlusMinusSatuLangkahSaja(): void
    {
        $secret = $this->s->totp->generateSecret();
        self::assertNotNull($this->s->totp->verify($secret, $this->codeNow($secret, -1), null));
        self::assertNotNull($this->s->totp->verify($secret, $this->codeNow($secret, 1), null));
        self::assertNull($this->s->totp->verify($secret, $this->codeNow($secret, 3), null));
        self::assertNull($this->s->totp->verify($secret, $this->codeNow($secret, -3), null));
    }

    public function testProvisioningUri(): void
    {
        $uri = $this->s->totp->provisioningUri('ABCDEFGH', 'ani@example.test');
        self::assertStringStartsWith('otpauth://totp/BikininSSO:ani%40example.test?', $uri);
        self::assertStringContainsString('secret=ABCDEFGH', $uri);
        self::assertStringContainsString('issuer=BikininSSO', $uri);
    }

    public function testCryptoRoundTripKeyringDanTamper(): void
    {
        $c = $this->s->crypto;
        $enc = $c->encrypt('rahasia');
        self::assertStringStartsWith('v1:', $enc);
        self::assertStringNotContainsString('rahasia', $enc);
        self::assertSame('rahasia', $c->decrypt($enc));
        self::assertNotSame($enc, $c->encrypt('rahasia'), 'nonce acak');
        // data lama (versi v0) tetap terbaca, reencrypt memindahkan ke versi current
        $old = new \App\Security\Crypto(['current' => 'v0', 'keys' => ['v0' => base64_encode(str_repeat('b', 32))]], str_repeat('h', 32));
        $legacy = $old->encrypt('lama');
        self::assertStringStartsWith('v0:', $legacy);
        self::assertSame('lama', $c->decrypt($legacy));
        self::assertStringStartsWith('v1:', $c->reencrypt($legacy));
        $this->expectException(\RuntimeException::class);
        $c->decrypt(substr($enc, 0, -3) . 'AAA');
    }

    public function testHmacDeterministikHex(): void
    {
        self::assertSame($this->s->crypto->hmac('x'), $this->s->crypto->hmac('x'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->s->crypto->hmac('x'));
    }

    public function testKodeCadanganSepuluhSekaliPakaiDanDisimpanSebagaiHmac(): void
    {
        [$user, , $plain] = $this->userWithMfa();
        self::assertCount(10, $plain);
        $raw = (string) $this->s->users->findById($user->id)?->mfaBackupCodes;
        foreach ($plain as $code) {
            self::assertStringNotContainsString($code, $raw);
        }
        self::assertTrue($this->s->backup->consume($user, strtoupper($plain[0])));
        self::assertFalse($this->s->backup->consume($user, $plain[0]), 'sekali pakai');
        self::assertFalse($this->s->backup->consume($user, 'zzzzz-zzzzz'));
        self::assertCount(9, json_decode((string) $this->s->users->findById($user->id)?->mfaBackupCodes, true));
    }

    public function testLoginMfaTotpBerhasilMembuatSesiMfaPassed(): void
    {
        [, $secret] = $this->userWithMfa();
        $r = $this->loginPassword();
        self::assertSame('/login/mfa', $r->headers['Location']);
        self::assertSame([], $r->cookies);
        self::assertSame(0, $this->sessionCount(), 'belum ada sesi SSO sebelum MFA lulus');

        $this->s->clock->advance('+31 seconds'); // step lebih baru dari mfa_last_step awal
        $r = $this->mfaController()->submit($this->post('/login/mfa', ['code' => $this->codeNow($secret)]));
        self::assertSame(303, $r->status);
        self::assertCount(1, $r->cookies);
        self::assertSame(1, $this->sessionCount());
        self::assertNotNull($this->s->sessions->findByIdHash(hash('sha256', $r->cookies[0]->value))?->mfaPassedAt);
    }

    public function testKodeSalahDitolakTanpaSesi(): void
    {
        $this->userWithMfa();
        $this->loginPassword();
        $r = $this->mfaController()->submit($this->post('/login/mfa', ['code' => '123456']));
        self::assertSame(401, $r->status);
        self::assertSame([], $r->cookies);
        self::assertSame(0, $this->sessionCount());
    }

    public function testReplayKodeTotpDitolak(): void
    {
        [, $secret] = $this->userWithMfa();
        $this->s->clock->advance('+31 seconds');
        $code = $this->codeNow($secret);
        $this->loginPassword();
        self::assertSame(303, $this->mfaController()->submit($this->post('/login/mfa', ['code' => $code]))->status);
        $this->loginPassword();
        self::assertSame(401, $this->mfaController()->submit($this->post('/login/mfa', ['code' => $code]))->status);
    }

    public function testKodeCadanganDiterimaSekali(): void
    {
        [, , $plain] = $this->userWithMfa();
        $this->loginPassword();
        self::assertSame(303, $this->mfaController()->submit($this->post('/login/mfa', ['code' => $plain[3]]))->status);
        $this->loginPassword();
        self::assertSame(401, $this->mfaController()->submit($this->post('/login/mfa', ['code' => $plain[3]]))->status);
    }

    public function testRateLimitMfaLimaGagalLaluDiblokirMeskiKodBenar(): void
    {
        [$user, $secret] = $this->userWithMfa();
        $this->s->clock->advance('+31 seconds');
        $this->loginPassword();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->mfaController()->submit($this->post('/login/mfa', ['code' => '000000']))->status);
        }
        $r = $this->mfaController()->submit($this->post('/login/mfa', ['code' => $this->codeNow($secret)]));
        self::assertSame(429, $r->status);
        self::assertSame([], $r->cookies);
        self::assertTrue($this->s->limiter->blocked('mfa:' . $user->id));
    }

    public function testTantanganKedaluwarsaTanpaPreMfa(): void
    {
        $this->userWithMfa();
        self::assertSame(303, $this->mfaController()->submit($this->post('/login/mfa', ['code' => '123456']))->status);
        $this->loginPassword();
        $this->s->clock->advance('+6 minutes');
        self::assertSame('/login', $this->mfaController()->submit($this->post('/login/mfa', ['code' => '123456']))->headers['Location']);
    }

    public function testSetupMfaMenampilkanQrDanKunciLaluAktifkan(): void
    {
        $u = $this->makeUser();
        $this->s->manager->start($u->id, 'password', false, $this->post('/', []));
        $cookie = $this->cookieOfOnlySession();
        $page = $this->setupController()->show($this->post('/account/mfa', [], $u, $cookie));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('data:image/svg+xml;base64,', $page->body);
        self::assertStringContainsString('otpauth://totp/', $page->body);
        self::assertSame('no-store', $page->headers['Cache-Control']);
        preg_match('/Kunci manual: <code>([A-Z2-7]+)</', $page->body, $m);
        $secret = $m[1] ?? '';
        self::assertNotSame('', $secret);

        $bad = $this->setupController()->enable($this->post('/account/mfa/enable', ['code' => '000000'], $u, $cookie));
        self::assertSame(422, $bad->status);
        self::assertNull($this->s->users->findById($u->id)?->mfaEnabledAt);

        $ok = $this->setupController()->enable($this->post('/account/mfa/enable', ['code' => $this->codeNow($secret)], $u, $cookie));
        self::assertSame(303, $ok->status);
        self::assertCount(1, $ok->cookies, 'id sesi diganti setelah MFA lulus');
        self::assertNotSame($cookie, $ok->cookies[0]->value);
        self::assertNull($this->s->sessions->findByIdHash(hash('sha256', $cookie)));
        $new = $this->s->sessions->findByIdHash(hash('sha256', $ok->cookies[0]->value));
        self::assertNotNull($new?->mfaPassedAt);

        $fresh = $this->s->users->findById($u->id);
        self::assertNotNull($fresh);
        self::assertNotNull($fresh->mfaEnabledAt);
        self::assertStringNotContainsString($secret, (string) $fresh->mfaSecretEnc);
        self::assertSame($secret, $this->s->crypto->decrypt((string) $fresh->mfaSecretEnc));

        // kode cadangan tampil sekali
        $after = $this->setupController()->show($this->post('/account/mfa', [], $fresh, $ok->cookies[0]->value));
        self::assertSame(10, preg_match_all('/<li><code>[a-z2-9]{5}-[a-z2-9]{5}<\/code><\/li>/', $after->body));
        $again = $this->setupController()->show($this->post('/account/mfa', [], $fresh, $ok->cookies[0]->value));
        self::assertStringNotContainsString('Kode cadangan', $again->body);
    }

    public function testNonaktifkanDanRegenerasiButuhPassword(): void
    {
        [$user, , $plain] = $this->userWithMfa();
        $this->s->manager->start($user->id, 'password', true, $this->post('/', []));
        $cookie = $this->cookieOfOnlySession();
        $c = $this->setupController();

        self::assertSame(422, $c->disable($this->post('/account/mfa/disable', ['password' => 'salah-salah-1'], $user, $cookie))->status);
        self::assertSame(422, $c->regenerateBackupCodes($this->post('/account/mfa/backup-codes', [], $user, $cookie))->status);
        self::assertNotNull($this->s->users->findById($user->id)?->mfaEnabledAt);

        $r = $c->regenerateBackupCodes($this->post('/account/mfa/backup-codes', ['password' => self::PW], $user, $cookie));
        self::assertSame(303, $r->status);
        $fresh = $this->s->users->findById($user->id) ?? $user;
        self::assertFalse($this->s->backup->consume($fresh, $plain[0]), 'kode lama tidak berlaku');

        $r = $c->disable($this->post('/account/mfa/disable', ['password' => self::PW], $user, $cookie));
        self::assertSame(303, $r->status);
        $off = $this->s->users->findById($user->id);
        self::assertNull($off?->mfaEnabledAt);
        self::assertNull($off?->mfaSecretEnc);
        self::assertNull($off?->mfaBackupCodes);
    }

    public function testMarkMfaPassedPadaSesiYangSudahPunyaSessionClients(): void
    {
        $u = $this->makeUser();
        $this->s->manager->start($u->id, 'password', false, $this->post('/', []));
        $cookie = $this->cookieOfOnlySession();
        $session = $this->s->sessions->findByIdHash(hash('sha256', $cookie));
        self::assertNotNull($session);
        $pdo = $this->s->db->pdo();
        $pdo->exec("INSERT INTO clients (client_id, name, allowed_grants) VALUES ('rp', 'rp', 'authorization_code')");
        $pdo->prepare('INSERT INTO session_clients (session_sid, client_id) VALUES (?, 1)')->execute([$session->sid]);

        $new = $this->s->manager->markMfaPassed($session);

        self::assertNull($this->s->sessions->findByIdHash($session->idHash));
        $rotated = $this->s->sessions->findByIdHash(hash('sha256', $new->value));
        self::assertSame($session->sid, $rotated?->sid, 'sid stabil');
        self::assertNotNull($rotated->mfaPassedAt);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM session_clients WHERE session_sid = ?');
        $stmt->execute([$session->sid]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'rujukan session_clients tetap utuh');
        // cascade via sid tetap bekerja
        $pdo->prepare('DELETE FROM sso_sessions WHERE sid = ?')->execute([$session->sid]);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM session_clients')->fetchColumn());
    }

    public function testLoggerMenyamarkanSecretTotpKodeDanUri(): void
    {
        $text = 'gagal secret JBSWY3DPEHPK3PXPJBSWY3DP kode abcde-fgh2k uri otpauth://totp/X:a?secret=AAAA';
        $out = Logger::redact($text);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $out);
        self::assertStringNotContainsString('abcde-fgh2k', $out);
        self::assertStringNotContainsString('otpauth://totp', $out);
    }

    private function cookieOfOnlySession(): string
    {
        // start() mengembalikan Cookie; tes ini membuat sesi lalu memulihkan nilainya lewat sesi baru yang diketahui.
        $value = bin2hex(random_bytes(32));
        $row = $this->s->db->pdo()->query('SELECT * FROM sso_sessions ORDER BY created_at DESC LIMIT 1')->fetch();
        $this->s->db->pdo()->prepare('UPDATE sso_sessions SET id_hash = ? WHERE id_hash = ?')->execute([hash('sha256', $value), $row['id_hash']]);

        return $value;
    }
}
