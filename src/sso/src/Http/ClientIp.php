<?php

declare(strict_types=1);

namespace App\Http;

/** Satu-satunya sumber IP klien (dipakai Request dan Audit). */
final class ClientIp
{
    /**
     * @param array<string,mixed> $server
     * @param array<mixed> $trustedProxies IP atau CIDR; entri tidak valid diabaikan
     */
    public static function resolve(array $server, array $trustedProxies = []): string
    {
        $remote = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }
        $remote = self::normalize($remote);
        $trusted = array_values(array_filter($trustedProxies, 'is_string'));
        if ($trusted === [] || !self::isTrusted($remote, $trusted)) {
            return $remote;
        }
        $xff = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (!is_string($xff) || $xff === '' || strlen($xff) > 2048) {
            return $remote;
        }
        $hops = array_reverse(explode(',', $xff));
        foreach ($hops as $hop) {
            $hop = trim($hop);
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                return $remote; // rantai rusak: jangan percaya sisanya
            }
            $hop = self::normalize($hop);
            if (!self::isTrusted($hop, $trusted)) {
                return $hop;
            }
        }

        return $remote;
    }

    /** IPv4-mapped IPv6 (::ffff:a.b.c.d) menjadi IPv4; selain itu tidak berubah. */
    private static function normalize(string $ip): string
    {
        $bin = inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $v4 = inet_ntop(substr($bin, 12));
            if ($v4 !== false) {
                return $v4;
            }
        }

        return $ip;
    }

    /** @param array<string> $list */
    public static function isTrusted(string $ip, array $list): bool
    {
        $ip = self::normalize($ip);
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($list as $entry) {
            $entry = trim($entry);
            $parts = explode('/', $entry);
            if (count($parts) > 2) {
                continue;
            }
            $netBin = filter_var($parts[0], FILTER_VALIDATE_IP) !== false ? inet_pton($parts[0]) : false;
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $max = strlen($bin) * 8;
            if (count($parts) === 1) {
                $bits = $max;
            } else {
                if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
                    continue;
                }
                $bits = (int) $parts[1];
            }
            if (self::prefixMatch($bin, $netBin, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function prefixMatch(string $a, string $b, int $bits): bool
    {
        $full = intdiv($bits, 8);
        if (substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
