<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Gc;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

final class GcTest extends TestCase
{
    private Stack $s;

    protected function setUp(): void
    {
        $this->s = new Stack();
    }

    private function rows(string $table): int
    {
        return (int) $this->s->db->pdo()->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    /** @param list<string|null> $p */
    private function exec(string $sql, array $p = []): void
    {
        $this->s->db->pdo()->prepare($sql)->execute($p);
    }

    public function testHapusYangKedaluwarsaDanSisakanYangMasihBerlaku(): void
    {
        $now = $this->s->clock->now();
        $f = static fn (string $m): string => $now->modify($m)->format('Y-m-d H:i:s');
        $this->exec("INSERT INTO federation_states (state_hash, provider_id, pending_request, expires_at) VALUES ('a', 1, '{}', ?), ('b', 1, '{}', ?)", [$f('-1 minute'), $f('+5 minutes')]);
        $this->exec("INSERT INTO login_attempts (identifier, ip_address, success, attempted_at) VALUES ('x', 'i', 0, ?), ('y', 'i', 0, ?)", [$f('-31 days'), $f('-29 days')]);
        $this->exec("INSERT INTO audit_logs (event, created_at) VALUES ('e', ?), ('e', ?)", [$f('-366 days'), $f('-300 days')]);
        $this->exec("INSERT INTO users (id, uuid, email, created_at, updated_at) VALUES (1, 'u', 'a@b.c', ?, ?)", [$f('-1 day'), $f('-1 day')]);
        $this->exec("INSERT INTO clients (id, client_id, name, allowed_grants) VALUES (1, 'c', 'C', 'authorization_code')");
        $ins = "INSERT INTO sso_sessions (id_hash, sid, user_id, auth_method, created_at, last_seen_at, expires_at, revoked_at) VALUES (?, ?, 1, 'password', ?, ?, ?, ?)";
        $this->exec($ins, ['h1', 's1', $f('-20 days'), $f('-20 days'), $f('-8 days'), null]);      // kedaluwarsa > 7 hari
        $this->exec($ins, ['h2', 's2', $f('-20 days'), $f('-20 days'), $f('-6 days'), null]);      // kedaluwarsa < 7 hari
        $this->exec($ins, ['h3', 's3', $f('-20 days'), $f('-20 days'), $f('+1 day'), $f('-8 days')]); // dicabut > 7 hari
        $this->exec($ins, ['h4', 's4', $f('-1 day'), $f('-1 day'), $f('+1 day'), null]);            // aktif
        $rt = "INSERT INTO refresh_tokens (token_hash, family_id, client_id, user_id, session_sid, scope, issued_at, expires_at, revoked_at) VALUES (?, 'f', 1, 1, 's4', 'openid', ?, ?, ?)";
        $this->exec($rt, ['r1', $f('-40 days'), $f('-8 days'), null]);
        $this->exec($rt, ['r2', $f('-40 days'), $f('+3 days'), $f('-8 days')]);
        $this->exec($rt, ['r3', $f('-1 day'), $f('+29 days'), null]);
        $ac = "INSERT INTO auth_codes (code_hash, client_id, user_id, session_sid, redirect_uri, scope, code_challenge, code_challenge_method, expires_at) VALUES (?, 1, 1, 's4', 'https://x', 'openid', 'c', 'S256', ?)";
        $this->exec($ac, ['c1', $f('-1 minute')]);
        $this->exec($ac, ['c2', $f('+1 minute')]);

        $out = (new Gc($this->s->db, $this->s->clock))->run();

        self::assertSame(1, $out['auth_codes']);
        self::assertSame(1, $out['federation_states']);
        self::assertSame(2, $out['refresh_tokens']);
        self::assertSame(2, $out['sso_sessions']);
        self::assertSame(1, $out['login_attempts']);
        self::assertSame(1, $out['audit_logs']);
        self::assertSame(1, $this->rows('auth_codes'));
        self::assertSame(1, $this->rows('refresh_tokens'));
        self::assertSame(2, $this->rows('sso_sessions'));
        self::assertSame(1, $this->rows('audit_logs'));
        // idempoten
        self::assertSame(0, array_sum((new Gc($this->s->db, $this->s->clock))->run()));
    }
}
