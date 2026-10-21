<?php

declare(strict_types=1);

namespace App\Admin;

use App\Repository\Record\SsoSession;
use App\Security\Crypto;
use App\Support\Clock;
use App\Support\UiSession;

/**
 * Aksi admin tertunda menunggu step-up. Disimpan terenkripsi di sesi UI (per browser), terikat ke sid sesi SSO dan
 * user, kedaluwarsa 5 menit, sekali pakai. Hanya POST ke /admin/* (bukan /admin/stepup).
 */
final class PendingActionStore
{
    public const TTL = 300;
    private const KEY = 'stepup_pending';
    private const MAX_BYTES = 65536;
    private const PATH = '#^/admin/[A-Za-z0-9._~/-]{1,200}$#';

    public function __construct(private readonly Crypto $crypto, private readonly Clock $clock)
    {
    }

    public static function pathAllowed(string $method, string $path): bool
    {
        return $method === 'POST'
            && preg_match(self::PATH, $path) === 1
            && !str_contains($path, '..')
            && !str_contains($path, '//')
            && rtrim($path, '/') !== '/admin/stepup';
    }

    /**
     * @param array<string,mixed> $body
     * @return bool false bila aksi tak boleh ditunda (tidak disimpan)
     */
    public function save(SsoSession $session, string $method, string $path, array $body): bool
    {
        UiSession::forget(self::KEY);
        if (!self::pathAllowed($method, $path)) {
            return false;
        }
        unset($body['_csrf']);
        $clean = self::cleanBody($body);
        $json = json_encode([
            'm' => $method, 'p' => $path, 'b' => $clean,
            'exp' => $this->clock->now()->getTimestamp() + self::TTL,
            'sid' => $session->sid, 'uid' => $session->userId,
        ], JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            return false;
        }
        UiSession::set(self::KEY, $this->crypto->encrypt($json));

        return true;
    }

    /**
     * Lihat tanpa mengonsumsi (untuk label di form).
     *
     * @return ?array{path: string, module: string, action: string}
     */
    public function peek(SsoSession $session): ?array
    {
        $p = $this->read(UiSession::get(self::KEY), $session);

        return $p === null ? null : ['path' => $p['p']] + self::describe($p['p']);
    }

    /**
     * Ambil dan hapus (sekali pakai). Null bila tak ada, kedaluwarsa, atau milik sesi/user lain.
     *
     * @return ?array{method: string, path: string, body: array<string,mixed>}
     */
    public function take(SsoSession $session): ?array
    {
        $raw = UiSession::pull(self::KEY);
        $p = $this->read($raw, $session);

        return $p === null ? null : ['method' => $p['m'], 'path' => $p['p'], 'body' => $p['b']];
    }

    public function clear(): void
    {
        UiSession::forget(self::KEY);
    }

    /** @return array{module: string, action: string} */
    public static function describe(string $path): array
    {
        $parts = array_values(array_filter(explode('/', substr($path, 7)), static fn (string $s): bool => $s !== ''));
        $module = $parts[0] ?? 'admin';
        $action = 'simpan';
        foreach (array_reverse(array_slice($parts, 1)) as $seg) {
            if (!ctype_digit($seg)) {
                $action = $seg;
                break;
            }
        }

        return ['module' => $module, 'action' => $action];
    }

    /** @return ?array{m: string, p: string, b: array<string,mixed>, exp: int, sid: string, uid: int} */
    private function read(mixed $raw, SsoSession $session): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $p = json_decode($this->crypto->decrypt($raw), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (
            !is_array($p) || !is_string($p['m'] ?? null) || !is_string($p['p'] ?? null) || !is_array($p['b'] ?? null)
            || !is_int($p['exp'] ?? null) || !is_string($p['sid'] ?? null) || !is_int($p['uid'] ?? null)
        ) {
            return null;
        }
        if (
            $p['exp'] < $this->clock->now()->getTimestamp()
            || !hash_equals($p['sid'], $session->sid) || $p['uid'] !== $session->userId
            || !self::pathAllowed($p['m'], $p['p'])
        ) {
            return null;
        }

        return $p;
    }

    /**
     * @param array<mixed> $body
     * @return array<string,mixed>
     */
    private static function cleanBody(array $body): array
    {
        $out = [];
        foreach ($body as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (is_string($v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = array_filter($v, static fn (mixed $x): bool => is_string($x));
            }
        }

        return $out;
    }
}
