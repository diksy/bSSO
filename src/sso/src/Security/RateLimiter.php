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

    /**
     * Batas IP atomik (20 gagal/15 menit): dicatat dengan identifier 'ip:<ip>' (alamat 0.0.0.0 agar tidak
     * dihitung ganda oleh countFailuresByIp) SEBELUM dihitung; bila melebihi IP_LIMIT baris dihapus dan null dikembalikan.
     * Panggil releaseIp() bila percobaan berhasil agar login sukses tidak menghabiskan kuota IP.
     */
    public function beginIp(string $ip): ?int
    {
        $id = 'ip:' . $ip;
        $rowId = $this->attempts->add($id, '0.0.0.0', false, $this->clock->now());
        if ($this->attempts->countFailures($id, $this->clock->now()->modify(self::WINDOW)) > self::IP_LIMIT) {
            $this->attempts->delete($rowId);

            return null;
        }

        return $rowId;
    }

    public function releaseIp(int $ipAttemptId): void
    {
        $this->attempts->delete($ipAttemptId);
    }

    /** @param string $identifier email, 'mfa:<user_id>' */
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
     * Catat kegagalan untuk pembatas generik TANPA alamat IP (0.0.0.0) agar tidak ikut kuota IP login
     * (register:, forgot:, dll. berdiri sendiri).
     */
    public function strike(string $identifier): void
    {
        $this->attempts->add(self::norm($identifier), '0.0.0.0', false, $this->clock->now());
    }

    /**
     * Pembatas atomik 5 gagal/15 menit: percobaan dicatat (sebagai gagal) SEBELUM diperiksa lalu dihitung, sehingga
     * request serentak tidak bisa sama-sama lolos. Null = diblokir. Panggil markSuccess() bila percobaan berhasil.
     */
    public function begin(string $identifier, string $ip): ?int
    {
        $id = self::norm($identifier);
        $rowId = $this->attempts->add($id, $ip, false, $this->clock->now());
        if ($this->attempts->countFailures($id, $this->clock->now()->modify(self::WINDOW)) > self::EMAIL_LIMIT) {
            $this->attempts->delete($rowId);

            return null;
        }

        return $rowId;
    }

    public function markSuccess(int $attemptId): void
    {
        $this->attempts->markSuccess($attemptId);
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

    /**
     * Endpoint klien (/token, /introspect, /revoke): batas per IP (semua klien) dan per pasangan klien+IP per menit.
     * Kunci tidak memuat klien saja agar pihak luar tak bisa menghabiskan kuota klien sah.
     */
    public function clientEndpointAllowed(string $scope, string $clientId, string $ip, int $perClient, int $perIp): bool
    {
        $since = $this->clock->now()->modify('-1 minute');
        $ipId = self::norm($scope . 'ip:' . $ip);
        $clientIpId = self::norm($scope . ':' . $clientId . '@' . $ip);
        if ($this->attempts->countAll($ipId, $since) >= $perIp || $this->attempts->countAll($clientIpId, $since) >= $perClient) {
            return false;
        }
        $now = $this->clock->now();
        $this->attempts->add($ipId, $ip, true, $now);
        $this->attempts->add($clientIpId, $ip, true, $now);

        return true;
    }

    private static function norm(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }
}
