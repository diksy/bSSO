<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Clock;

/**
 * Token stateless HMAC-SHA256 (verifikasi email 24 jam, reset password 1 jam). Tanpa tabel;
 * `bind` mengikat token ke keadaan saat ini (mis. SHA-256 password_hash) sehingga otomatis sekali pakai.
 */
final class SignedTokens
{
    public const VERIFY = 'verify';
    public const RESET = 'reset';
    private const TTL = [self::VERIFY => 86400, self::RESET => 3600];

    public function __construct(private readonly string $key, private readonly Clock $clock)
    {
        if (strlen($key) < 16) {
            throw new \InvalidArgumentException('hmac_key terlalu pendek atau belum diisi');
        }
    }

    public function issue(string $purpose, int $userId, string $bind): string
    {
        $ttl = self::TTL[$purpose] ?? throw new \InvalidArgumentException('Tujuan token tidak dikenal');
        $payload = self::b64(json_encode(
            ['p' => $purpose, 'u' => $userId, 'e' => $this->clock->now()->getTimestamp() + $ttl, 'b' => self::bindHash($bind)],
            JSON_THROW_ON_ERROR,
        ));

        return $payload . '.' . $this->mac($purpose, $payload);
    }

    /** @return ?array{uid: int, bind: string} null bila rusak, salah tujuan, atau kedaluwarsa */
    public function verify(string $purpose, string $token): ?array
    {
        if (strlen($token) > 512 || preg_match('/^[A-Za-z0-9_-]+\.[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        [$payload, $mac] = explode('.', $token, 2);
        if (!hash_equals($this->mac($purpose, $payload), $mac)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (
            !is_array($data) || ($data['p'] ?? null) !== $purpose || !is_int($data['u'] ?? null)
            || !is_int($data['e'] ?? null) || !is_string($data['b'] ?? null)
            || $data['e'] < $this->clock->now()->getTimestamp()
        ) {
            return null;
        }

        return ['uid' => $data['u'], 'bind' => $data['b']];
    }

    /** Cocokkan keadaan terkini dengan bind di token (waktu-konstan). */
    public static function bindMatches(string $tokenBind, string $current): bool
    {
        return hash_equals($tokenBind, self::bindHash($current));
    }

    private static function bindHash(string $bind): string
    {
        return hash('sha256', $bind);
    }

    private function mac(string $purpose, string $payload): string
    {
        return hash_hmac('sha256', $purpose . '|' . $payload, $this->key);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
