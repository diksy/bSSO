<?php

declare(strict_types=1);

namespace App\Session;

use App\Http\Cookie;
use App\Http\Request;
use App\Repository\Record\SsoSession;
use App\Repository\SsoSessionRepository;
use App\Support\Clock;

/**
 * Status autentikasi hanya di tabel sso_sessions; cookie menyimpan 32 byte acak (hex), DB menyimpan SHA-256-nya.
 * Idle timeout 8 jam; batas mutlak 24 jam (30 hari bila "ingat saya").
 */
final class SsoSessionManager
{
    public const COOKIE = '__Host-bsso';
    public const IDLE_SECONDS = 8 * 3600;
    public const ABSOLUTE_SECONDS = 24 * 3600;
    public const REMEMBER_SECONDS = 30 * 86400;
    private const TOUCH_INTERVAL = 60;

    public function __construct(private readonly SsoSessionRepository $sessions, private readonly Clock $clock)
    {
    }

    public function start(int $userId, string $authMethod, bool $mfaPassed, Request $request, bool $remember = false): Cookie
    {
        $now = $this->clock->now();
        $value = bin2hex(random_bytes(32));
        $expires = $now->modify('+' . ($remember ? self::REMEMBER_SECONDS : self::ABSOLUTE_SECONDS) . ' seconds');
        $this->sessions->create(
            self::hash($value),
            self::uuid4(),
            $userId,
            mb_substr($authMethod, 0, 30),
            $mfaPassed ? $now : null,
            $request->ip(),
            $request->headers['user-agent'] ?? null,
            $now,
            $expires,
        );

        return $this->cookie($value, $remember ? $expires : null);
    }

    public function current(Request $request): ?SsoSession
    {
        $value = $request->cookies[self::COOKIE] ?? '';
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            return null;
        }
        $session = $this->sessions->findByIdHash(self::hash($value));
        if ($session === null || $session->revokedAt !== null) {
            return null;
        }
        $now = $this->clock->now();
        if ($session->expiresAt <= $now || $session->lastSeenAt->getTimestamp() + self::IDLE_SECONDS <= $now->getTimestamp()) {
            return null;
        }

        return $session;
    }

    /** Ganti id sesi setelah MFA. */
    public function markMfaPassed(SsoSession $session): Cookie
    {
        $now = $this->clock->now();
        $value = bin2hex(random_bytes(32));
        if (!$this->sessions->replaceIdHash($session->idHash, self::hash($value), $now)) {
            throw new \RuntimeException('Sesi tidak dapat diperbarui');
        }
        $remember = $session->expiresAt->getTimestamp() - $session->createdAt->getTimestamp() > self::ABSOLUTE_SECONDS;

        return $this->cookie($value, $remember ? $session->expiresAt : null);
    }

    public function touch(SsoSession $session): void
    {
        $now = $this->clock->now();
        if ($now->getTimestamp() - $session->lastSeenAt->getTimestamp() >= self::TOUCH_INTERVAL) {
            $this->sessions->touch($session->idHash, $now);
        }
    }

    public function clearCookie(): Cookie
    {
        return new Cookie(self::COOKIE, '', 1);
    }

    private function cookie(string $value, ?\DateTimeImmutable $expires): Cookie
    {
        return new Cookie(self::COOKIE, $value, $expires?->getTimestamp() ?? 0);
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }
}
