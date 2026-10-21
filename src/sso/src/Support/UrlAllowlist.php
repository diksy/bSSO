<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Penjaga URL keluar (SSRF): hanya https, port 443, tanpa userinfo, host berupa nama DNS publik atau IP literal publik.
 * Semua alamat hasil resolusi DNS harus publik (bukan loopback, privat, link-local, metadata cloud, multicast, reserved).
 * Hasil resolusi dikembalikan agar pemanggil menyematkan (pin) koneksi ke IP itu (mencegah DNS rebinding).
 */
final class UrlAllowlist
{
    private const MAX_URL = 500;

    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const V6_BLOCKED = [
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001::/32', '2001:db8::/32', '2002::/16',
        '64:ff9b::/96', '100::/64',
    ];

    private const BAD_SUFFIXES = ['.local', '.localhost', '.internal', '.localdomain', '.lan', '.intranet', '.corp', '.home.arpa'];

    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /** @param ?\Closure(string): list<string> $resolver nama host → daftar IP (untuk tes); default DNS sistem */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::systemResolver(...);
    }

    public function isAllowed(string $url): bool
    {
        return $this->resolve($url) !== null;
    }

    /**
     * @return ?array{host: string, port: int, ip: string} null bila URL ditolak
     */
    public function resolve(string $url): ?array
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || !isset($parts['host'])) {
            return null;
        }
        $port = $parts['port'] ?? 443;
        if ($port !== 443) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? ['host' => $host, 'port' => 443, 'ip' => $host] : null;
        }
        if (!self::validHostname($host)) {
            return null;
        }
        $ips = ($this->resolver)($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        // Utamakan IPv4 (CURLOPT_RESOLVE paling andal).
        usort($ips, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return ['host' => $host, 'port' => 443, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) dan IPv4-compatible: nilai dinilai sebagai IPv4.
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return self::isPublicIp((string) inet_ntop(substr($bin, 12)));
            }
            foreach (self::V6_BLOCKED as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }

            return true;
        }
        foreach (self::V4_BLOCKED as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function validHostname(string $host): bool
    {
        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            return false;
        }
        foreach (self::BAD_SUFFIXES as $suffix) {
            if (str_ends_with('.' . $host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = (string) inet_pton($net);
        if (strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function systemResolver(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $r) {
            $ip = $r['ip'] ?? $r['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }

        return array_values(array_unique($ips));
    }
}
