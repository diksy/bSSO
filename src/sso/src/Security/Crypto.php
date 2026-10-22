<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Enkripsi sodium secretbox berkeyring + HMAC. Kunci dari secrets.php (base64, 32 byte); tidak pernah dicatat ke log.
 * Format ciphertext: '<versi>:<base64(nonce + ciphertext)>'.
 */
final class Crypto
{
    /** @var array<string,string> versi => kunci biner */
    private array $keys = [];
    private string $current;

    /** @param array{current?: string, keys?: array<string,string>} $keyring */
    public function __construct(array $keyring, private readonly string $hmacKey)
    {
        foreach (($keyring['keys'] ?? []) as $version => $b64) {
            $raw = base64_decode((string) $b64, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('Kunci keyring tidak valid (wajib base64 dari 32 byte)');
            }
            $this->keys[(string) $version] = $raw;
        }
        $this->current = (string) ($keyring['current'] ?? '');
        if (!isset($this->keys[$this->current])) {
            throw new \RuntimeException('Versi keyring "current" tidak ada');
        }
        if (strlen($this->hmacKey) < 16) {
            throw new \RuntimeException('hmac_key terlalu pendek');
        }
    }

    /** @return string '<versi>:<base64(nonce+ciphertext)>' memakai versi keyring 'current' */
    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plain, $nonce, $this->keys[$this->current]);

        return $this->current . ':' . base64_encode($nonce . $box);
    }

    public function decrypt(string $ciphertext): string
    {
        $pos = strpos($ciphertext, ':');
        if ($pos === false) {
            throw new \RuntimeException('Format ciphertext tidak valid');
        }
        $version = substr($ciphertext, 0, $pos);
        $raw = base64_decode(substr($ciphertext, $pos + 1), true);
        if (!isset($this->keys[$version]) || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Ciphertext tidak dapat didekripsi');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->keys[$version],
        );
        if ($plain === false) {
            throw new \RuntimeException('Ciphertext tidak dapat didekripsi');
        }

        return $plain;
    }

    /** HMAC-SHA256 dengan secrets.php['hmac_key'], hex. */
    public function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, $this->hmacKey);
    }

    public function currentVersion(): string
    {
        return $this->current;
    }

    /** Versi keyring pada ciphertext, atau null bila formatnya tidak dikenali. */
    public function versionOf(string $ciphertext): ?string
    {
        $pos = strpos($ciphertext, ':');

        return $pos === false || $pos === 0 ? null : substr($ciphertext, 0, $pos);
    }

    public function reencrypt(string $ciphertext): string
    {
        return $this->encrypt($this->decrypt($ciphertext));
    }
}
