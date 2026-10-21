<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Clock;

/** TOTP RFC 6238 (SHA-1, 6 digit, 30 detik) dengan jendela ±1 langkah. */
final class Totp
{
    public const PERIOD = 30;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly Clock $clock, private readonly string $issuer = 'BikininSSO')
    {
    }

    /** Secret 20 byte acak, base32. */
    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $label): string
    {
        $label = rawurlencode($this->issuer) . ':' . rawurlencode(str_replace(["\r", "\n", ':'], ' ', $label));

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $this->issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return int|null step yang lolos (jendela ±1), null jika gagal atau step <= $lastStep */
    public function verify(string $secret, string $code, ?int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secret);
        if ($key === '') {
            return null;
        }
        $now = intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
        $matched = null;
        // Periksa semua kandidat (waktu konstan terhadap posisi cocok); ambil step terbesar yang cocok.
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if (hash_equals($this->code($key, $step), $code)) {
                $matched = $step;
            }
        }
        if ($matched === null || ($lastStep !== null && $matched <= $lastStep)) {
            return null;
        }

        return $matched;
    }

    public function code(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $o = ord($hash[19]) & 0x0f;
        $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(rtrim(str_replace(' ', '', $s), '='));
        $bits = '';
        foreach (str_split($s) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                return '';
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
