<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\LoginAttemptRepository;
use App\Support\Clock;

final class RateLimiter
{
    public const EMAIL_LIMIT = 5;
    public const IP_LIMIT = 20;
    public const WINDOW = '-15 minutes';

    public function __construct(private readonly LoginAttemptRepository $attempts, private readonly Clock $clock)
    {
    }

    /** 5 gagal per email / 20 gagal per IP dalam 15 menit. */
    public function loginBlocked(string $email, string $ip): bool
    {
        $since = $this->clock->now()->modify(self::WINDOW);

        return $this->attempts->countFailures(self::norm($email), $since) >= self::EMAIL_LIMIT
            || $this->attempts->countFailuresByIp($ip, $since) >= self::IP_LIMIT;
    }

    /** @param string $identifier email, 'token:<client_id>', atau 'mfa:<user_id>' */
    public function record(string $identifier, string $ip, bool $success): void
    {
        $this->attempts->add(self::norm($identifier), $ip, $success, $this->clock->now());
    }

    /** Pembatas generik 5 gagal/15 menit untuk identifier (register:<ip>, forgot:<email>, pwchange:<id>, mfa:<id>). */
    public function blocked(string $identifier): bool
    {
        return $this->attempts->countFailures(self::norm($identifier), $this->clock->now()->modify(self::WINDOW)) >= self::EMAIL_LIMIT;
    }

    /**
     * Pembatas hitung-semua: tolak bila sudah ada $max catatan dalam jendela; bila lolos, catat satu percobaan.
     * Dipakai endpoint yang menulis ke DB tanpa autentikasi (mis. awal federasi: 'fedstart:<ip>').
     */
    public function hit(string $identifier, string $ip, int $max, string $window = self::WINDOW): bool
    {
        $id = self::norm($identifier);
        if ($this->attempts->countAll($id, $this->clock->now()->modify($window)) >= $max) {
            return false;
        }
        $this->attempts->add($id, $ip, true, $this->clock->now());

        return true;
    }

    /** 60 request per klien per menit; request yang diizinkan dicatat. */
    public function tokenAllowed(string $clientId): bool
    {
        $id = 'token:' . $clientId;
        if ($this->attempts->countAll($id, $this->clock->now()->modify('-1 minute')) >= 60) {
            return false;
        }
        $this->attempts->add($id, '0.0.0.0', true, $this->clock->now());

        return true;
    }

    private static function norm(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }
}
