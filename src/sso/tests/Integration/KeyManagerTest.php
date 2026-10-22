<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\DiscoveryController;
use App\Controller\JwksController;
use App\Http\Request;
use App\Repository\AuditLogRepository;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Security\KeyManager;
use App\Support\Audit;
use App\Support\Database;
use App\Support\FrozenClock;
use App\Support\Urls;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

/** SQLite in-memory + direktori temp (BUKAN MariaDB). */
final class KeyManagerTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private KeyManager $km;
    private string $dir;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->dir = sys_get_temp_dir() . '/bsso-keys-' . bin2hex(random_bytes(6));
        $this->km = $this->make();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function make(): KeyManager
    {
        $audit = new Audit(new AuditLogRepository($this->db), $this->clock, null);

        return new KeyManager(new SigningKeyRepository($this->db), new SettingRepository($this->db), $this->db, $this->clock, $audit, $this->dir);
    }

    /** Rotasi terjadwal pada instance baru (cache kosong). */
    private function due(int $step): ?\App\Security\RotationResult
    {
        $this->calls += $step;

        return $this->make()->rotateIfDue();
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return array_map('strval', $this->db->pdo()->query('SELECT status FROM signing_keys ORDER BY created_at, status')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private function claims(): array
    {
        $t = $this->clock->now()->getTimestamp();

        return ['iss' => 'x', 'sub' => 'u', 'iat' => $t, 'exp' => $t + 900];
    }

    public function testInstalasiBaruMembuatKunciAktifDenganFile600(): void
    {
        $r = $this->km->rotateIfDue();
        self::assertNotNull($r);
        self::assertSame(['created_active'], $r->actions);
        $key = $this->km->active();
        self::assertSame($r->activeKid, $key->kid);
        self::assertSame(0600, fileperms($key->privateKeyPath) & 0777);
        self::assertSame(0700, fileperms($this->dir) & 0777);
        self::assertSame([], array_filter(scandir($this->dir) ?: [], static fn (string $f): bool => str_starts_with($f, '.tmp-')));
        // kid = thumbprint RFC 7638
        $jwk = $this->km->jwks()['keys'][0];
        $expected = rtrim(strtr(base64_encode(hash('sha256', '{"e":"' . $jwk['e'] . '","kty":"RSA","n":"' . $jwk['n'] . '"}', true)), '+/', '-_'), '=');
        self::assertSame($expected, $jwk['kid']);
        self::assertStringNotContainsString('PRIVATE', (string) json_encode($this->km->jwks()));
        self::assertNull($this->km->rotateIfDue());
    }

    public function testInstalasiKosongMengunciBarisSentinelDanHanyaSatuKunciAktif(): void
    {
        $this->make()->rotateIfDue();
        $this->make()->rotateIfDue(); // proses kedua (cache kosong) tidak membuat kunci aktif kedua
        $n = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM signing_keys WHERE status = 'active'")->fetchColumn();
        self::assertSame(1, $n);
        $lock = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM settings WHERE name = 'lock.signing_keys'")->fetchColumn();
        self::assertSame(1, $lock, 'baris sentinel untuk SELECT ... FOR UPDATE pada instalasi kosong');
    }

    public function testSiklusPenuhTerjadwal(): void
    {
        $this->km->rotateIfDue();
        $first = $this->km->active()->kid;
        $this->clock->advance('+87 days'); // 90 - 2 hari tercapai? 87 < 88
        self::assertNull($this->due(1));
        $this->clock->advance('+1 day'); // 88 hari
        $r = $this->due(2);
        self::assertNotNull($r);
        self::assertSame(['created_next'], $r->actions);
        $next = $r->nextKid;
        self::assertCount(2, $this->km->jwks()['keys']);
        self::assertNull($this->due(3)); // idempoten
        $this->clock->advance('+2 days'); // 90 hari, next berumur 2 hari
        $r = $this->due(4);
        self::assertNotNull($r);
        self::assertSame(['activated'], $r->actions);
        self::assertSame($next, $r->activeKid);
        $km = $this->make();
        self::assertSame($next, $km->active()->kid);
        $kids = array_column($km->jwks()['keys'], 'kid');
        self::assertContains($first, $kids); // retiring tetap di JWKS
        $this->clock->advance('+2 days');
        $r = $this->due(5);
        self::assertNotNull($r);
        self::assertSame(['retired'], $r->actions);
        self::assertSame([$next], array_column($this->make()->jwks()['keys'], 'kid'));
        self::assertFileDoesNotExist($this->dir . '/' . $first . '.pem');
    }

    public function testSignVerifyDanKunciRetiringMasihValid(): void
    {
        $this->km->rotateIfDue();
        $jwt = $this->km->sign($this->claims(), 'at+jwt');
        $h = json_decode((string) base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);
        self::assertSame(['at+jwt', 'RS256', $this->km->active()->kid], [$h['typ'], $h['alg'], $h['kid']]);
        self::assertSame('u', $this->km->verify($jwt)['sub']);
        $this->clock->advance('+88 days');
        $this->due(6);
        $this->clock->advance('+2 days');
        $this->due(7); // jwt lama kini retiring
        $this->clock->advance('+1 minute');
        $km = $this->make();
        $km->rotate(); // paksa: tanpa next -> buat next
        self::assertSame('u', $km->verify($jwt, true)['sub']);
    }

    public function testTolakAlgNoneHs256KidAsingKedaluwarsa(): void
    {
        $this->km->rotateIfDue();
        $b = static fn (array $a): string => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');
        $kid = $this->km->active()->kid;
        $none = $b(['alg' => 'none', 'typ' => 'JWT', 'kid' => $kid]) . '.' . $b($this->claims()) . '.';
        $hs = JWT::encode($this->claims(), 'secret-secret-secret-secret-1234', 'HS256', $kid);
        $noKid = $b(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b($this->claims()) . '.AAAA';
        $unknown = $b(['alg' => 'RS256', 'kid' => 'tidak-dikenal']) . '.' . $b($this->claims()) . '.AAAA';
        foreach ([$none, $hs, $noKid, $unknown, 'bukan-jwt', ''] as $bad) {
            try {
                $this->km->verify($bad);
                self::fail('harus ditolak: ' . substr($bad, 0, 20));
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        // tanda tangan rusak
        $jwt = $this->km->sign($this->claims(), 'JWT');
        $this->expectBadSignature($jwt . 'x');
        // kedaluwarsa
        $jwt = $this->km->sign($this->claims(), 'JWT');
        $this->clock->advance('+1 hour');
        try {
            $this->km->verify($jwt);
            self::fail('kedaluwarsa harus ditolak');
        } catch (\UnexpectedValueException) {
            self::assertSame('u', $this->km->verify($jwt, true)['sub']);
        }
    }

    private function expectBadSignature(string $jwt): void
    {
        try {
            $this->km->verify($jwt);
            $this->fail('tanda tangan rusak harus ditolak');
        } catch (\UnexpectedValueException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRotasiDaruratMencabutSemuaDanMengaudit(): void
    {
        $this->km->rotateIfDue();
        $old = $this->km->active();
        $jwt = $this->km->sign($this->claims(), 'JWT');
        $this->km->rotate(); // buat next
        $r = $this->make()->rotate(true);
        self::assertContains('emergency', $r->actions);
        self::assertNotSame($old->kid, $r->activeKid);
        self::assertFileDoesNotExist($old->privateKeyPath);
        $km = $this->make();
        self::assertSame([$r->activeKid], array_column($km->jwks()['keys'], 'kid'));
        $this->expectException(\UnexpectedValueException::class);
        try {
            $km->verify($jwt, true);
        } finally {
            $n = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE event = 'key.emergency_rotation'")->fetchColumn();
            self::assertSame(1, $n);
        }
    }

    public function testRotasiPaksaDuaTahap(): void
    {
        $this->km->rotate();
        $a = $this->km->active()->kid;
        $r1 = $this->make()->rotate();
        self::assertSame(['created_next'], $r1->actions);
        $r2 = $this->make()->rotate();
        self::assertSame(['activated'], $r2->actions);
        self::assertSame($r1->nextKid, $r2->activeKid);
        $st = $this->statuses();
        sort($st);
        self::assertSame(['active', 'retiring'], $st);
        self::assertContains($a, array_column($this->make()->jwks()['keys'], 'kid'));
    }

    public function testSettingRotationDaysDipakai(): void
    {
        $this->km->rotateIfDue();
        $this->db->pdo()->exec("INSERT INTO settings (name, value) VALUES ('key.rotation_days', '10'), ('key.overlap_days', '1')");
        $this->clock->advance('+9 days');
        self::assertSame(['created_next'], $this->due(8)?->actions);
    }

    public function testSignMenolakTypAsingDanIzinFileLonggar(): void
    {
        $this->km->rotateIfDue();
        try {
            $this->km->sign($this->claims(), 'foo');
            self::fail();
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        chmod($this->km->active()->privateKeyPath, 0644);
        $this->expectException(\RuntimeException::class);
        $this->make()->sign($this->claims(), 'JWT');
    }

    public function testJwksDanDiscoveryController(): void
    {
        $this->km->rotateIfDue();
        $req = new Request('GET', '/jwks', [], [], [], []);
        $res = (new JwksController($this->km))->show($req);
        self::assertSame(200, $res->status);
        self::assertSame('public, max-age=3600', $res->headers['Cache-Control'] ?? $res->headers['cache-control'] ?? null);
        $body = json_decode($res->body, true);
        self::assertSame('RS256', $body['keys'][0]['alg']);
        self::assertArrayNotHasKey('d', $body['keys'][0]);

        $d = json_decode((new DiscoveryController(new Urls('https://sso.contoh.id')))->show($req)->body, true);
        self::assertSame('https://sso.contoh.id', $d['issuer']);
        self::assertSame('https://sso.contoh.id/jwks', $d['jwks_uri']);
        self::assertSame(['RS256'], $d['id_token_signing_alg_values_supported']);
        self::assertSame(['S256'], $d['code_challenge_methods_supported']);
    }
}
