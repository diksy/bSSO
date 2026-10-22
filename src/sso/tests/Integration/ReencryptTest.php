<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Security\Crypto;
use App\Security\Reencryptor;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDb;

final class ReencryptTest extends TestCase
{
    private const K1 = 'a';
    private const K2 = 'b';

    private function crypto(bool $both, string $current): Crypto
    {
        $keys = ['v1' => base64_encode(str_repeat(self::K1, 32))];
        if ($both) {
            $keys['v2'] = base64_encode(str_repeat(self::K2, 32));
        }

        return new Crypto(['current' => $current, 'keys' => $keys], str_repeat('h', 32));
    }

    public function testReencryptFlow(): void
    {
        $db = TestDb::create();
        $pdo = $db->pdo();
        $old = $this->crypto(false, 'v1');
        $new = $this->crypto(true, 'v2');
        $now = '2026-01-01 00:00:00';
        $ins = $pdo->prepare('INSERT INTO users (uuid, email, mfa_secret_enc, created_at, updated_at) VALUES (?,?,?,?,?)');
        $ins->execute(['u1', 'a@x.id', $old->encrypt('SECRET1'), $now, $now]);
        $ins->execute(['u2', 'b@x.id', $new->encrypt('SECRET2'), $now, $now]); // sudah v2
        $ins->execute(['u3', 'c@x.id', null, $now, $now]);
        $ins->execute(['u4', 'd@x.id', 'v1:rusak', $now, $now]);
        $pdo->prepare('INSERT INTO identity_providers (slug, display_name, protocol, driver, client_id, client_secret_enc, scopes) VALUES (?,?,?,?,?,?,?)')
            ->execute(['apple', 'Apple', 'oidc', 'Apple', 'cid', $old->encrypt('-----P8-----'), 'openid']);

        $tool = new Reencryptor($db, $new);
        $before = $pdo->query('SELECT mfa_secret_enc FROM users WHERE uuid = \'u1\'')->fetchColumn();

        $dry = $tool->run(true);
        $this->assertSame(2, $dry['updated']);
        $this->assertSame(1, $dry['failed']);
        $this->assertSame($before, $pdo->query('SELECT mfa_secret_enc FROM users WHERE uuid = \'u1\'')->fetchColumn());

        $r = $tool->run(false);
        $this->assertSame(2, $r['updated']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(['gagal: users.mfa_secret_enc id=4'], $r['errors']);
        $this->assertStringNotContainsString('rusak', implode('', $r['errors']));

        $v1 = (string) $pdo->query('SELECT mfa_secret_enc FROM users WHERE uuid = \'u1\'')->fetchColumn();
        $this->assertSame('v2', $new->versionOf($v1));
        $this->assertSame('SECRET1', $new->decrypt($v1));
        $p8 = (string) $pdo->query('SELECT client_secret_enc FROM identity_providers')->fetchColumn();
        $this->assertSame('-----P8-----', $new->decrypt($p8));

        $again = $tool->run(false);
        $this->assertSame(0, $again['updated']);
        $this->assertSame(1, $again['failed']);
        $this->assertSame($v1, (string) $pdo->query('SELECT mfa_secret_enc FROM users WHERE uuid = \'u1\'')->fetchColumn());
    }
}
