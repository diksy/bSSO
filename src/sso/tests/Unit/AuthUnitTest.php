<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Account\AdminCreator;
use App\Http\Request;
use App\Security\PasswordHasher;
use App\Security\SignedTokens;
use App\Support\Audit;
use App\Support\FrozenClock;
use App\Support\Logger;
use App\Support\SafePath;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

final class AuthUnitTest extends TestCase
{
    public function testPasswordHasherArgon2idDanVerify(): void
    {
        $h = new PasswordHasher();
        $hash = $h->hash('rahasia-rahasia');
        if (defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('$argon2id$', $hash);
        }
        self::assertTrue($h->verify('rahasia-rahasia', $hash));
        self::assertFalse($h->verify('lain', $hash));
        self::assertFalse($h->verify('x', ''));
        self::assertFalse($h->needsRehash($hash));
    }

    public function testSignedTokens(): void
    {
        $clock = new FrozenClock('2026-01-01 00:00:00');
        $t = new SignedTokens(str_repeat('k', 32), $clock);
        $tok = $t->issue(SignedTokens::RESET, 7, 'hash-lama');
        $v = $t->verify(SignedTokens::RESET, $tok);
        self::assertSame(7, $v['uid']);
        self::assertTrue(SignedTokens::bindMatches($v['bind'], 'hash-lama'));
        self::assertFalse(SignedTokens::bindMatches($v['bind'], 'hash-baru'));
        self::assertNull($t->verify(SignedTokens::VERIFY, $tok));
        self::assertNull((new SignedTokens(str_repeat('z', 32), $clock))->verify(SignedTokens::RESET, $tok));
        [$payload, $mac] = explode('.', $tok);
        self::assertNull($t->verify(SignedTokens::RESET, $payload . 'A.' . $mac));
        self::assertNull($t->verify(SignedTokens::RESET, "$payload.$mac\n"));
        self::assertNull($t->verify(SignedTokens::RESET, ''));
        $clock->advance('+3601 seconds');
        self::assertNull($t->verify(SignedTokens::RESET, $tok));
        $v2 = $t->issue(SignedTokens::VERIFY, 7, 'a@b.c');
        $clock->advance('-3601 seconds');
        $clock->advance('+23 hours');
        self::assertNotNull($t->verify(SignedTokens::VERIFY, $v2));
    }

    public function testSignedTokensMenolakKunciKosong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignedTokens('', new FrozenClock());
    }

    public function testSafePath(): void
    {
        self::assertSame('/authorize?a=1&b=2', SafePath::clean('/authorize?a=1&b=2'));
        foreach ([null, '', 'https://x.test', '//x.test', '/\\x', 'javascript:1', "/a\nb"] as $bad) {
            self::assertSame('/account', SafePath::clean($bad));
        }
    }

    public function testAuditMenyaringKunciRahasia(): void
    {
        $out = Audit::scrub(['method' => 'password', 'password' => 'x', 'client_secret' => 'y', 'Token' => 'z', 'x' => ['auth_code' => 'c', 'ok' => 1]]);
        self::assertSame(['method' => 'password', 'x' => ['ok' => 1]], $out);
    }

    public function testAuditMenyimpanIpDanContext(): void
    {
        $s = new Stack();
        $s->audit->record('login.success', 1, 1, null, ['password' => 'x', 'method' => 'password']);
        $row = $s->db->pdo()->query('SELECT * FROM audit_logs')->fetch();
        self::assertSame('login.success', $row['event']);
        self::assertSame('{"method":"password"}', $row['context']);
        self::assertSame(inet_pton('203.0.113.9'), $row['ip_address']);
    }

    public function testLoggerRedactMenyamarkanRahasia(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
        $samples = [
            'password=hunter2xyz' => 'hunter2xyz',
            'Authorization: Bearer abc.def-ghi123' => 'abc.def-ghi123',
            '{"client_secret": "s3cr3t-value"}' => 's3cr3t-value',
            "token='abcdef123456'" => 'abcdef123456', // gitleaks:allow
            "SQLSTATE: Duplicate entry 'ani@example.test' for key" => 'ani@example.test',
            "gagal $jwt" => $jwt,
            'kunci ' . bin2hex(random_bytes(32)) => '',
        ];
        foreach ($samples as $in => $secret) {
            $out = Logger::redact($in);
            if ($secret !== '') {
                self::assertStringNotContainsString($secret, $out, $in);
            }
            self::assertStringContainsString('[', $out);
        }
        self::assertSame('Koneksi database gagal', Logger::redact('Koneksi database gagal'));
    }

    public function testLoggerExceptionTidakMenulisRahasia(): void
    {
        $dir = sys_get_temp_dir() . '/bsso-log-' . bin2hex(random_bytes(4));
        (new Logger($dir))->exception(new \RuntimeException('login gagal password=SuperRahasia123 untuk ani@example.test'));
        $log = (string) file_get_contents($dir . '/app-' . gmdate('Y-m-d') . '.log');
        self::assertStringNotContainsString('SuperRahasia123', $log);
        self::assertStringNotContainsString('ani@example.test', $log);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testAdminCreator(): void
    {
        $s = new Stack();
        $c = new AdminCreator($s->db, $s->users, $s->roles, $s->hasher, $s->audit, $s->clock);
        foreach ([['bukan-email', 'A', 'password-panjang-1'], ['a@example.test', '', 'password-panjang-1'], ['a@example.test', 'A', 'pendek']] as [$e, $n, $p]) {
            try {
                $c->create($e, $n, $p);
                self::fail('Harus ditolak');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $s->roles->count());
            }
        }
        $id = $c->create('Admin@Example.test', 'Admin', 'password-panjang-1');
        self::assertSame('superadmin', $s->roles->findByUserId($id)?->role);
        $u = $s->users->findById($id);
        self::assertNotNull($u);
        self::assertSame('admin@example.test', $u->email);
        self::assertSame('active', $u->status);
        self::assertNotNull($u->emailVerifiedAt);
        self::assertTrue($s->hasher->verify('password-panjang-1', (string) $u->passwordHash));
        $this->expectException(\RuntimeException::class);
        $c->create('lain@example.test', 'Lain', 'password-panjang-1');
    }

    public function testRateLimiterTokenDanRequestIp(): void
    {
        $s = new Stack();
        for ($i = 0; $i < 60; $i++) {
            self::assertTrue($s->limiter->tokenAllowed('klien-a'));
        }
        self::assertFalse($s->limiter->tokenAllowed('klien-a'));
        self::assertTrue($s->limiter->tokenAllowed('klien-b'));
        $s->clock->advance('+61 seconds');
        self::assertTrue($s->limiter->tokenAllowed('klien-a'));
        self::assertSame('198.51.100.7', (new Request('GET', '/', remoteAddr: '198.51.100.7'))->ip());
    }
}
