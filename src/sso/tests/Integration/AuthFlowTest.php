<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\Account\AccountPage;
use App\Controller\Account\PasswordController;
use App\Controller\Account\ProfileController;
use App\Controller\LoginController;
use App\Controller\PasswordResetController;
use App\Controller\RegisterController;
use App\Http\Middleware\RequireSsoSession;
use App\Http\Request;
use App\Http\Response;
use App\Support\UiSession;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Memakai SQLite in-memory (bukan MariaDB): menguji logika, bukan dialek SQL produksi. */
final class AuthFlowTest extends TestCase
{
    private Stack $s;
    private const PW = 'kata-sandi-panjang-1';

    protected function setUp(): void
    {
        UiSession::fake();
        $this->s = new Stack();
    }

    protected function tearDown(): void
    {
        UiSession::reset();
    }

    private function login(): LoginController
    {
        $s = $this->s;

        return new LoginController($s->view, $s->users, $s->hasher, $s->limiter, $s->manager, $s->audit, $s->clock);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $cookies
     */
    private function post(string $path, array $body, array $cookies = []): Request
    {
        return new Request('POST', $path, [], $body, ['user-agent' => 'PHPUnit'], $cookies, [], '198.51.100.7');
    }

    private function makeUser(string $email = 'ani@example.test', string $status = 'active'): int
    {
        $u = $this->s->users->create($email, $this->s->hasher->hash(self::PW), 'Ani', $status);
        $this->s->users->markEmailVerified($u->id, $this->s->clock->now());
        if ($status !== 'active') {
            $this->s->db->pdo()->exec("UPDATE users SET status = '$status' WHERE id = {$u->id}");
        }

        return $u->id;
    }

    private function statusOf(string $email): ?string
    {
        return $this->s->users->findByEmail($email)?->status;
    }

    private function cookieOf(Response $r): string
    {
        self::assertNotEmpty($r->cookies);

        return $r->cookies[0]->value;
    }

    public function testLoginBerhasilMembuatSesiDanCookieAman(): void
    {
        $id = $this->makeUser();
        $r = $this->login()->submit($this->post('/login', ['email' => ' ANI@example.test ', 'password' => self::PW, 'next' => '/authorize?client_id=x']));
        self::assertSame(303, $r->status);
        self::assertSame('/authorize?client_id=x', $r->headers['Location']);
        $c = $r->cookies[0];
        self::assertSame('__Host-bsso', $c->name);
        self::assertTrue($c->secure && $c->httpOnly);
        self::assertSame('Lax', $c->sameSite);
        self::assertSame('/', $c->path);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $c->value);
        // DB hanya menyimpan hash, bukan nilai cookie
        $row = $this->s->db->pdo()->query('SELECT * FROM sso_sessions')->fetch();
        self::assertSame(hash('sha256', $c->value), $row['id_hash']);
        self::assertNotSame($c->value, $row['id_hash']);
        self::assertSame($id, (int) $row['user_id']);
        self::assertNull($row['mfa_passed_at']);
    }

    public function testPesanGagalSamaUntukEmailTidakAdaDanPasswordSalah(): void
    {
        $this->makeUser();
        $a = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => 'salah-salah-salah']));
        $b = $this->login()->submit($this->post('/login', ['email' => 'tidakada@example.test', 'password' => 'salah-salah-salah']));
        self::assertSame(401, $a->status);
        self::assertSame($a->status, $b->status);
        self::assertStringContainsString('Email atau kata sandi salah.', $a->body);
        self::assertStringContainsString('Email atau kata sandi salah.', $b->body);
        self::assertSame([], $a->cookies);
        self::assertSame(0, (int) $this->s->db->pdo()->query('SELECT COUNT(*) FROM sso_sessions')->fetchColumn());
    }

    public function testPercobaanKeEnamDalam15MenitDitolakMeskiPasswordBenar(): void
    {
        $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => 'salah-salah-salah']));
            self::assertSame(401, $r->status);
        }
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        self::assertSame(429, $r->status);
        self::assertSame([], $r->cookies);
        // setelah jendela lewat, boleh lagi
        $this->s->clock->advance('+16 minutes');
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        self::assertSame(303, $r->status);
    }

    public function testBatasPerIp(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->s->limiter->record("u$i@example.test", '198.51.100.7', false);
        }
        self::assertTrue($this->s->limiter->loginBlocked('baru@example.test', '198.51.100.7'));
        self::assertFalse($this->s->limiter->loginBlocked('baru@example.test', '198.51.100.8'));
    }

    public function testNextEksternDiabaikan(): void
    {
        $this->makeUser();
        foreach (['https://evil.test/', '//evil.test', '/\\evil.test', "/ok\r\nSet-Cookie: x=1"] as $bad) {
            $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW, 'next' => $bad]));
            self::assertSame('/account', $r->headers['Location'], $bad);
        }
    }

    public function testUserDenganMfaTidakLangsungDapatSesi(): void
    {
        $id = $this->makeUser();
        $this->s->db->pdo()->exec("UPDATE users SET mfa_enabled_at = '2026-01-01 00:00:00' WHERE id = $id");
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        self::assertSame('/login/mfa', $r->headers['Location']);
        self::assertSame([], $r->cookies);
        $pre = UiSession::get('pre_mfa');
        self::assertIsArray($pre);
        self::assertSame($id, $pre['user_id']);
    }

    public function testUserSuspendedDitolakGenerik(): void
    {
        $this->makeUser('s@example.test', 'suspended');
        $r = $this->login()->submit($this->post('/login', ['email' => 's@example.test', 'password' => self::PW]));
        self::assertSame(401, $r->status);
        self::assertStringContainsString('Email atau kata sandi salah.', $r->body);
    }

    public function testHashLamaDiRehashSaatLogin(): void
    {
        $id = $this->makeUser();
        $old = password_hash(self::PW, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->s->users->updatePasswordHash($id, $old);
        if (!$this->s->hasher->needsRehash($old)) {
            self::markTestSkipped('Argon2id tidak tersedia; bcrypt dipakai');
        }
        $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $new = $this->s->users->findById($id)?->passwordHash;
        self::assertNotSame($old, $new);
        self::assertStringStartsWith('$argon2id$', (string) $new);
    }

    public function testSesiTidakDipakaiLagiSetelahIdleAtauMutlak(): void
    {
        $this->makeUser();
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $req = new Request('GET', '/account', cookies: ['__Host-bsso' => $this->cookieOf($r)]);
        self::assertNotNull($this->s->manager->current($req));
        $this->s->clock->advance('+9 hours');
        self::assertNull($this->s->manager->current($req), 'idle 8 jam');

        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $req = new Request('GET', '/account', cookies: ['__Host-bsso' => $this->cookieOf($r)]);
        for ($i = 0; $i < 4; $i++) { // aktif terus tiap 7 jam
            $this->s->clock->advance('+7 hours');
            $cur = $this->s->manager->current($req);
            if ($cur !== null) {
                $this->s->manager->touch($cur);
            }
        }
        self::assertNull($this->s->manager->current($req), 'batas mutlak 24 jam');
    }

    public function testIngatSayaMemperpanjangBatasMutlak(): void
    {
        $this->makeUser();
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW, 'remember' => '1']));
        self::assertGreaterThan($this->s->clock->now()->getTimestamp() + 29 * 86400, $r->cookies[0]->expires);
    }

    public function testMarkMfaPassedMenggantiIdSesi(): void
    {
        $this->makeUser();
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $old = $this->cookieOf($r);
        $session = $this->s->manager->current(new Request('GET', '/', cookies: ['__Host-bsso' => $old]));
        self::assertNotNull($session);
        $cookie = $this->s->manager->markMfaPassed($session);
        self::assertNotSame($old, $cookie->value);
        self::assertNull($this->s->manager->current(new Request('GET', '/', cookies: ['__Host-bsso' => $old])));
        $new = $this->s->manager->current(new Request('GET', '/', cookies: ['__Host-bsso' => $cookie->value]));
        self::assertNotNull($new);
        self::assertNotNull($new->mfaPassedAt);
    }

    public function testCookieMalformedAtauSesiDicabutDitolak(): void
    {
        $this->makeUser();
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $val = $this->cookieOf($r);
        foreach (['', 'abc', str_repeat('Z', 64), "$val'--"] as $bad) {
            self::assertNull($this->s->manager->current(new Request('GET', '/', cookies: ['__Host-bsso' => $bad])));
        }
        $sid = (string) $this->s->db->pdo()->query('SELECT sid FROM sso_sessions')->fetchColumn();
        $this->s->terminator->endSession($sid, 'test');
        self::assertNull($this->s->manager->current(new Request('GET', '/', cookies: ['__Host-bsso' => $val])));
    }

    public function testMiddlewareRequireSsoSession(): void
    {
        $this->makeUser();
        $mw = new RequireSsoSession($this->s->manager, $this->s->users);
        $next = static fn (Request $r): Response => Response::html('ok');

        $get = $mw->process(new Request('GET', '/account/password', ['a' => 'b']), $next);
        self::assertSame(302, $get->status);
        self::assertSame('/login?return=' . rawurlencode('/account/password?a=b'), $get->headers['Location']);
        $post = $mw->process(new Request('POST', '/account'), $next);
        self::assertSame(303, $post->status);
        self::assertSame('/login', $post->headers['Location']);

        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $ok = $mw->process(new Request('GET', '/account', cookies: ['__Host-bsso' => $this->cookieOf($r)]), $next);
        self::assertSame('ok', $ok->body);
    }

    private function passwordController(): PasswordController
    {
        $s = $this->s;

        return new PasswordController(new AccountPage($s->view, $s->roles), $s->users, $s->hasher, $s->limiter, $s->terminator, $s->audit, $s->db);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0: Request, 1: int} request terautentikasi dengan atribut dari middleware
     */
    private function authed(string $path, array $body): array
    {
        $existing = $this->s->users->findByEmail('ani@example.test');
        $id = $existing === null ? $this->makeUser() : $existing->id;
        $r = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $req = $this->post($path, $body, ['__Host-bsso' => $this->cookieOf($r)]);
        $captured = null;
        (new RequireSsoSession($this->s->manager, $this->s->users))->process($req, function (Request $r2) use (&$captured): Response {
            $captured = $r2;

            return Response::html('');
        });
        self::assertInstanceOf(Request::class, $captured);

        return [$captured, $id];
    }

    public function testGantiPasswordMengakhiriSesiLainTapiBukanSesiIni(): void
    {
        $this->makeUser();
        $other = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        [$req, $id] = $this->authed('/account/password', ['current' => self::PW, 'new' => 'password-baru-123', 'confirm' => 'password-baru-123']);
        $res = $this->passwordController()->save($req);
        self::assertSame(303, $res->status);
        self::assertTrue($this->s->hasher->verify('password-baru-123', (string) $this->s->users->findById($id)?->passwordHash));
        self::assertNull($this->s->manager->current(new Request('GET', '/', cookies: ['__Host-bsso' => $this->cookieOf($other)])));
        self::assertNotNull($this->s->manager->current($req));
    }

    public function testGantiPasswordMenolakInputTidakValid(): void
    {
        foreach (
            [
            ['current' => 'salah', 'new' => 'password-baru-123', 'confirm' => 'password-baru-123'],
            ['current' => self::PW, 'new' => 'pendek', 'confirm' => 'pendek'],
            ['current' => self::PW, 'new' => 'password-baru-123', 'confirm' => 'beda-beda-beda'],
            ['current' => self::PW, 'new' => self::PW, 'confirm' => self::PW],
            ] as $body
        ) {
            $this->setUp();
            [$req, $id] = $this->authed('/account/password', $body);
            $before = $this->s->users->findById($id)?->passwordHash;
            $res = $this->passwordController()->save($req);
            self::assertSame(422, $res->status);
            self::assertSame($before, $this->s->users->findById($id)?->passwordHash);
        }
    }

    public function testGantiPasswordDibatasiBruteForce(): void
    {
        [$req] = $this->authed('/account/password', ['current' => 'salah', 'new' => 'password-baru-123', 'confirm' => 'password-baru-123']);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->passwordController()->save($req)->status);
        }
        self::assertSame(429, $this->passwordController()->save($req)->status);
    }

    public function testProfilValidasiDanSimpan(): void
    {
        [$req, $id] = $this->authed('/account', ['name' => "  Budi\x00 Santoso "]);
        $c = new ProfileController(new AccountPage($this->s->view, $this->s->roles), $this->s->users, $this->s->audit);
        self::assertSame(303, $c->save($req)->status);
        self::assertSame('Budi Santoso', $this->s->users->findById($id)?->name);
        [$bad] = $this->authed('/account', ['name' => str_repeat('a', 81)]);
        self::assertSame(422, $c->save($bad)->status);
        $page = $c->show($req);
        self::assertStringContainsString('ani@example.test', $page->body);
    }

    private function register(): RegisterController
    {
        $s = $this->s;

        return new RegisterController($s->view, $s->users, $s->hasher, $s->limiter, $s->tokens, $s->mailer, $s->urls, $s->audit, $s->logger);
    }

    public function testRegisterVerifikasiEmailDanLoginSetelahnya(): void
    {
        $res = $this->register()->submit($this->post('/register', ['email' => 'Baru@Example.test', 'name' => 'Baru', 'password' => self::PW]));
        self::assertSame(200, $res->status);
        self::assertCount(1, $this->s->mailer->sent);
        $u = $this->s->users->findByEmail('baru@example.test');
        self::assertSame('pending', $u?->status);
        // belum bisa login sebelum verifikasi
        $r = $this->login()->submit($this->post('/login', ['email' => 'baru@example.test', 'password' => self::PW]));
        self::assertSame(403, $r->status);
        // verifikasi (idempoten)
        $token = $this->s->mailer->lastToken();
        for ($i = 0; $i < 2; $i++) {
            $v = $this->register()->verify(new Request('GET', '/verify-email', ['token' => $token]));
            self::assertSame('/login', $v->headers['Location']);
        }
        self::assertSame('active', $this->statusOf('baru@example.test'));
        $r = $this->login()->submit($this->post('/login', ['email' => 'baru@example.test', 'password' => self::PW]));
        self::assertSame(303, $r->status);
    }

    public function testRegisterEmailSudahAdaTidakBocor(): void
    {
        $this->makeUser();
        $a = $this->register()->submit($this->post('/register', ['email' => 'ani@example.test', 'name' => 'X', 'password' => self::PW]));
        $b = $this->register()->submit($this->post('/register', ['email' => 'lain@example.test', 'name' => 'X', 'password' => self::PW]));
        self::assertSame($a->status, $b->status);
        self::assertStringContainsString('Cek email Anda', $a->body);
        self::assertStringContainsString('Cek email Anda', $b->body);
        self::assertCount(1, $this->s->mailer->sent, 'email hanya dikirim ke yang baru');
        self::assertSame('Ani', $this->s->users->findByEmail('ani@example.test')?->name);
    }

    public function testTokenVerifikasiKedaluwarsaDanDipalsukanDitolak(): void
    {
        $this->register()->submit($this->post('/register', ['email' => 'baru@example.test', 'name' => 'Baru', 'password' => self::PW]));
        $token = $this->s->mailer->lastToken();
        $this->s->clock->advance('+25 hours');
        $this->register()->verify(new Request('GET', '/verify-email', ['token' => $token]));
        self::assertSame('pending', $this->statusOf('baru@example.test'));
        $this->s->clock->advance('-25 hours');
        $this->register()->verify(new Request('GET', '/verify-email', ['token' => substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a')]));
        self::assertNotSame('active', $this->statusOf('baru@example.test'));
        // token reset tidak berlaku sebagai token verifikasi
        $reset = $this->s->tokens->issue('reset', 1, 'x');
        self::assertNull($this->s->tokens->verify('verify', $reset));
    }

    public function testRegisterValidasiDanRateLimit(): void
    {
        self::assertSame(422, $this->register()->submit($this->post('/register', ['email' => 'bukan-email', 'name' => 'X', 'password' => self::PW]))->status);
        self::assertSame(422, $this->register()->submit($this->post('/register', ['email' => 'a@example.test', 'name' => 'X', 'password' => 'pendek']))->status);
        self::assertSame(0, (int) $this->s->db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        for ($i = 0; $i < 3; $i++) {
            $this->register()->submit($this->post('/register', ['email' => 'bukan-email', 'name' => 'X', 'password' => self::PW]));
        }
        self::assertSame(429, $this->register()->submit($this->post('/register', ['email' => 'a@example.test', 'name' => 'X', 'password' => self::PW]))->status);
    }

    private function reset(): PasswordResetController
    {
        $s = $this->s;

        return new PasswordResetController($s->view, $s->users, $s->hasher, $s->limiter, $s->tokens, $s->mailer, $s->urls, $s->terminator, $s->audit, $s->db, $s->logger);
    }

    public function testLupaPasswordResponsGenerik(): void
    {
        $this->makeUser();
        $a = $this->reset()->forgotSubmit($this->post('/password/forgot', ['email' => 'ani@example.test']));
        $b = $this->reset()->forgotSubmit($this->post('/password/forgot', ['email' => 'tidakada@example.test']));
        self::assertSame($a->status, $b->status);
        self::assertSame($a->headers['Location'], $b->headers['Location']);
        $flash = \App\Support\Flash::pull();
        self::assertCount(2, $flash);
        self::assertSame($flash[0]['message'], $flash[1]['message']);
        self::assertCount(1, $this->s->mailer->sent);
        self::assertSame('ani@example.test', $this->s->mailer->sent[0]['to']);
    }

    public function testResetPasswordSekaliPakaiDanMengakhiriSemuaSesi(): void
    {
        $this->makeUser();
        $login = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => self::PW]));
        $this->reset()->forgotSubmit($this->post('/password/forgot', ['email' => 'ani@example.test']));
        $token = $this->s->mailer->lastToken();

        self::assertSame(200, $this->reset()->resetForm(new Request('GET', '/password/reset', ['token' => $token]))->status);
        $res = $this->reset()->resetSubmit($this->post('/password/reset', ['token' => $token, 'new' => 'password-baru-123', 'confirm' => 'password-baru-123']));
        self::assertSame(303, $res->status);
        self::assertNull($this->s->manager->current(new Request('GET', '/', cookies: ['__Host-bsso' => $this->cookieOf($login)])));

        // pakai ulang gagal (terikat ke hash lama)
        $again = $this->reset()->resetSubmit($this->post('/password/reset', ['token' => $token, 'new' => 'password-lain-4567', 'confirm' => 'password-lain-4567']));
        self::assertSame(400, $again->status);
        self::assertSame(400, $this->reset()->resetForm(new Request('GET', '/password/reset', ['token' => $token]))->status);
        $ok = $this->login()->submit($this->post('/login', ['email' => 'ani@example.test', 'password' => 'password-baru-123']));
        self::assertSame(303, $ok->status);
    }

    public function testResetTokenKedaluwarsaDanPolicy(): void
    {
        $this->makeUser();
        $this->reset()->forgotSubmit($this->post('/password/forgot', ['email' => 'ani@example.test']));
        $token = $this->s->mailer->lastToken();
        $short = $this->reset()->resetSubmit($this->post('/password/reset', ['token' => $token, 'new' => 'pendek', 'confirm' => 'pendek']));
        self::assertSame(422, $short->status);
        $this->s->clock->advance('+61 minutes');
        self::assertSame(400, $this->reset()->resetForm(new Request('GET', '/password/reset', ['token' => $token]))->status);
    }

    public function testPraPembajakanAkunPendingDipulihkanLewatReset(): void
    {
        // Penyerang mendaftar dengan email korban dan password miliknya.
        $this->register()->submit($this->post('/register', ['email' => 'korban@example.test', 'name' => 'X', 'password' => 'password-penyerang-1']));
        $verify = $this->s->mailer->lastToken();
        // Korban memakai "lupa kata sandi" pada akun pending: tautan terkirim.
        $this->reset()->forgotSubmit($this->post('/password/forgot', ['email' => 'korban@example.test']));
        self::assertCount(2, $this->s->mailer->sent);
        $token = $this->s->mailer->lastToken();
        $this->reset()->resetSubmit($this->post('/password/reset', ['token' => $token, 'new' => 'password-korban-123', 'confirm' => 'password-korban-123']));
        self::assertSame('active', $this->statusOf('korban@example.test'));
        self::assertNotSame(303, $this->login()->submit($this->post('/login', ['email' => 'korban@example.test', 'password' => 'password-penyerang-1']))->status);
        self::assertSame(303, $this->login()->submit($this->post('/login', ['email' => 'korban@example.test', 'password' => 'password-korban-123']))->status);
        // Tautan verifikasi lama tidak mengubah apa pun pada password.
        $this->register()->verify(new Request('GET', '/verify-email', ['token' => $verify]));
        self::assertNotSame(303, $this->login()->submit($this->post('/login', ['email' => 'korban@example.test', 'password' => 'password-penyerang-1']))->status);
    }

    public function testPraPembajakanDaftarUlangMenimpaAkunPendingDanMenggugurkanTokenLama(): void
    {
        // Penyerang mendaftar lebih dulu dengan email korban.
        $this->register()->submit($this->post('/register', ['email' => 'korban@example.test', 'name' => 'Penyerang', 'password' => 'password-penyerang-1']));
        $attackerToken = $this->s->mailer->lastToken();
        // Korban mendaftar sendiri: respons seragam, email verifikasi baru terkirim.
        $res = $this->register()->submit($this->post('/register', ['email' => 'korban@example.test', 'name' => 'Korban', 'password' => 'password-korban-123']));
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Cek email Anda', $res->body);
        self::assertCount(2, $this->s->mailer->sent);
        $victimToken = $this->s->mailer->lastToken();
        self::assertNotSame($attackerToken, $victimToken);
        self::assertSame('Korban', $this->s->users->findByEmail('korban@example.test')?->name);

        // Token penyerang ditolak (terikat ke password_hash lama).
        $this->register()->verify(new Request('GET', '/verify-email', ['token' => $attackerToken]));
        self::assertSame('pending', $this->statusOf('korban@example.test'));
        // Token korban sah.
        $this->register()->verify(new Request('GET', '/verify-email', ['token' => $victimToken]));
        self::assertSame('active', $this->statusOf('korban@example.test'));
        self::assertNotSame(303, $this->login()->submit($this->post('/login', ['email' => 'korban@example.test', 'password' => 'password-penyerang-1']))->status);
        self::assertSame(303, $this->login()->submit($this->post('/login', ['email' => 'korban@example.test', 'password' => 'password-korban-123']))->status);

        // Setelah aktif, pendaftaran ulang tidak menimpa apa pun dan tidak mengirim email.
        $this->register()->submit($this->post('/register', ['email' => 'korban@example.test', 'name' => 'Penyerang', 'password' => 'password-penyerang-2']));
        self::assertCount(2, $this->s->mailer->sent);
        self::assertSame('Korban', $this->s->users->findByEmail('korban@example.test')?->name);
        self::assertSame(303, $this->login()->submit($this->post('/login', ['email' => 'korban@example.test', 'password' => 'password-korban-123']))->status);
    }

    public function testCariUserMenganggapPersenGarisBawahDanSeruLiteral(): void
    {
        foreach (['a%b@example.test', 'a_b@example.test', 'a!b@example.test', 'axb@example.test'] as $e) {
            $this->s->users->create($e, 'h', 'N', 'active');
        }
        foreach (['a%b' => 'a%b@example.test', 'a_b' => 'a_b@example.test', 'a!b' => 'a!b@example.test'] as $q => $want) {
            $r = $this->s->users->search($q);
            self::assertCount(1, $r, $q);
            self::assertSame($want, $r[0]->email);
        }
    }

    public function testForgotDibatasi5Per15Menit(): void
    {
        $this->makeUser();
        for ($i = 0; $i < 8; $i++) {
            $this->reset()->forgotSubmit($this->post('/password/forgot', ['email' => 'ani@example.test']));
        }
        self::assertCount(5, $this->s->mailer->sent);
    }
}
