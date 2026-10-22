<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\Record\SigningKey;
use App\Repository\SettingRepository;
use App\Repository\SigningKeyRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Database;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** Siklus hidup kunci RS256 (requirements §7). Private key hanya berupa file 0600 di $keyDir, tidak pernah di DB/log. */
final class KeyManager
{
    private const TYPES = ['JWT', 'at+jwt', 'logout+jwt'];
    public const DEFAULT_ROTATION_DAYS = 90;
    private const DEFAULT_OVERLAP_DAYS = 2;
    private const LEEWAY = 30;

    private ?SigningKey $activeCache = null;
    /** @var array<string,string> kid => PEM private */
    private array $privateCache = [];

    public function __construct(
        private readonly SigningKeyRepository $keys,
        private readonly SettingRepository $settings,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly string $keyDir,
    ) {
    }

    public function active(): SigningKey
    {
        if ($this->activeCache === null) {
            $key = $this->keys->findActive();
            if ($key === null) {
                throw new \RuntimeException('Tidak ada signing key aktif');
            }
            $this->activeCache = $key;
        }

        return $this->activeCache;
    }

    /** @return array{keys: list<array<string,string>>} kunci next/active/retiring */
    public function jwks(): array
    {
        $out = [];
        foreach ($this->keys->listByStatus('next', 'active', 'retiring') as $key) {
            $out[] = self::jwk($key);
        }

        return ['keys' => $out];
    }

    /**
     * @param array<string,mixed> $claims
     * @param string $typ header typ: 'JWT' | 'at+jwt' | 'logout+jwt'
     */
    public function sign(array $claims, string $typ): string
    {
        if (!in_array($typ, self::TYPES, true)) {
            throw new \InvalidArgumentException('typ tidak didukung');
        }
        $key = $this->active();

        return JWT::encode($claims, $this->privatePem($key), 'RS256', $key->kid, ['typ' => $typ]);
    }

    /**
     * Hanya RS256; kid wajib dan berstatus next/active/retiring.
     *
     * @return array<string,mixed> claims
     */
    public function verify(string $jwt, bool $allowExpired = false): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 8192) {
            throw new \UnexpectedValueException('Token tidak valid');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            throw new \UnexpectedValueException('Algoritma tidak diizinkan');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw new \UnexpectedValueException('kid wajib');
        }
        $key = $this->keys->findByKid($kid);
        if ($key === null || !in_array($key->status, ['next', 'active', 'retiring'], true) || $key->alg !== 'RS256') {
            throw new \UnexpectedValueException('kid tidak dikenal');
        }

        $prevLeeway = JWT::$leeway;
        $prevTs = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        try {
            $claims = JWT::decode($jwt, new Key($key->publicKeyPem, 'RS256'));
        } catch (ExpiredException $e) {
            if (!$allowExpired) {
                throw $e;
            }
            $claims = $e->getPayload();
        } finally {
            JWT::$leeway = $prevLeeway;
            JWT::$timestamp = $prevTs;
        }

        /** @var array<string,mixed> $arr */
        $arr = json_decode((string) json_encode($claims), true);

        return $arr;
    }

    /** Rotasi paksa (admin): tanpa kunci next → buat next; dengan next → promosikan. Darurat → kunci baru aktif, lainnya retired. */
    /** @param array<string,mixed> $context tambahan untuk audit rotasi darurat (mis. 'before') */
    public function rotate(bool $emergency = false, ?int $actorId = null, array $context = []): RotationResult
    {
        $result = $this->db->transaction(function () use ($emergency): array {
            $now = $this->clock->now();
            $this->settings->lock('lock.signing_keys', $now);
            $live = $this->keys->listLiveForUpdate();
            $actions = [];
            $delete = [];
            $by = static fn (string $s): ?SigningKey => array_values(array_filter($live, static fn (SigningKey $k): bool => $k->status === $s))[0] ?? null;
            $active = $by('active');
            $next = $by('next');

            if ($emergency) {
                $new = $this->generate('active', $now);
                foreach ($live as $k) {
                    $this->keys->markRetired($k->kid, $now);
                    $delete[] = $k->privateKeyPath;
                }
                $actions = ['emergency', 'created_active'];
                $newKid = $new;
                $nextKid = null;
            } elseif ($active === null) {
                $newKid = $this->generate('active', $now);
                $nextKid = $next?->kid;
                $actions[] = 'created_active';
            } elseif ($next === null) {
                $nextKid = $this->generate('next', $now);
                $newKid = $active->kid;
                $actions[] = 'created_next';
            } else {
                $this->keys->markRetiring($active->kid, $now);
                $this->keys->activate($next->kid, $now);
                $newKid = $next->kid;
                $nextKid = null;
                $actions[] = 'activated';
            }

            return [$actions, $newKid, $nextKid, $delete];
        });
        [$actions, $activeKid, $nextKid, $delete] = $result;
        $this->afterChange($delete);
        if ($emergency) {
            $this->audit->record('key.emergency_rotation', $actorId, null, null, $context + ['kid' => $activeKid]);
        }

        return new RotationResult($actions, $activeKid, $nextKid);
    }

    /** Rotasi terjadwal (key.rotation_days), idempoten; null jika belum jatuh tempo. */
    public function rotateIfDue(): ?RotationResult
    {
        $rotation = max(1, $this->intSetting('key.rotation_days', self::DEFAULT_ROTATION_DAYS));
        $overlap = max(0, $this->intSetting('key.overlap_days', self::DEFAULT_OVERLAP_DAYS));

        /** @var array{0: list<string>, 1: ?string, 2: ?string, 3: list<string>} $r */
        $r = $this->db->transaction(function () use ($rotation, $overlap): array {
            $now = $this->clock->now();
            $this->settings->lock('lock.signing_keys', $now);
            $live = $this->keys->listLiveForUpdate();
            $actions = [];
            $delete = [];
            $age = static fn (?\DateTimeImmutable $since): int => $since === null ? 0 : max(0, $now->getTimestamp() - $since->getTimestamp());
            $days = static fn (int $d): int => $d * 86400;

            $active = null;
            $next = null;
            foreach ($live as $k) {
                if ($k->status === 'active') {
                    $active = $k;
                } elseif ($k->status === 'next') {
                    $next = $k;
                }
            }

            if ($active === null) {
                // Instalasi baru (atau tanpa kunci aktif): promosikan next jika ada, jika tidak buat aktif.
                if ($next !== null) {
                    $this->keys->activate($next->kid, $now);
                    $activeKid = $next->kid;
                    $next = null;
                    $actions[] = 'activated';
                } else {
                    $activeKid = $this->generate('active', $now);
                    $actions[] = 'created_active';
                }

                return [$actions, $activeKid, null, $delete];
            }

            foreach ($live as $k) {
                if ($k->status === 'retiring' && $age($k->retiringAt) >= $days($overlap)) {
                    $this->keys->markRetired($k->kid, $now);
                    $delete[] = $k->privateKeyPath;
                    $actions[] = 'retired';
                }
            }

            $activeKid = $active->kid;
            if (
                $next !== null && $age($next->createdAt) >= $days($overlap)
                && $age($active->activatedAt ?? $active->createdAt) >= $days($rotation)
            ) {
                $this->keys->markRetiring($active->kid, $now);
                $this->keys->activate($next->kid, $now);
                $activeKid = $next->kid;
                $next = null;
                $actions[] = 'activated';
            } elseif ($next === null && $age($active->activatedAt ?? $active->createdAt) >= $days(max(0, $rotation - $overlap))) {
                $nextKid = $this->generate('next', $now);
                $actions[] = 'created_next';

                return [$actions, $activeKid, $nextKid, $delete];
            }

            return [$actions, $activeKid, $next?->kid, $delete];
        });

        [$actions, $activeKid, $nextKid, $delete] = $r;
        if ($actions === []) {
            return null;
        }
        $this->afterChange($delete);

        return new RotationResult($actions, $activeKid, $nextKid);
    }

    /** @param list<string> $paths */
    private function afterChange(array $paths): void
    {
        $this->activeCache = null;
        $this->privateCache = [];
        foreach ($paths as $path) {
            $real = $this->safePath($path);
            if ($real !== null) {
                @unlink($real);
            }
        }
    }

    /** Buat pasangan kunci, tulis file private atomik (0600), simpan baris DB. Mengembalikan kid. */
    private function generate(string $status, \DateTimeImmutable $now): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            throw new \RuntimeException('Gagal membuat kunci RSA');
        }
        $private = '';
        if (!openssl_pkey_export($res, $private)) {
            throw new \RuntimeException('Gagal mengekspor kunci RSA');
        }
        $details = openssl_pkey_get_details($res);
        if ($details === false || !isset($details['key'], $details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('Gagal membaca detail kunci RSA');
        }
        $kid = self::thumbprint(JWT::urlsafeB64Encode($details['rsa']['n']), JWT::urlsafeB64Encode($details['rsa']['e']));

        $path = $this->writePrivate($kid, $private);
        try {
            $this->keys->insert($kid, $details['key'], $path, $status, $now);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return $kid;
    }

    private function writePrivate(string $kid, string $pem): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,64}$/', $kid) !== 1) {
            throw new \RuntimeException('kid tidak valid');
        }
        if (!is_dir($this->keyDir) && !@mkdir($this->keyDir, 0700, true) && !is_dir($this->keyDir)) {
            throw new \RuntimeException('Direktori kunci tidak dapat dibuat');
        }
        $dir = realpath($this->keyDir);
        if ($dir === false) {
            throw new \RuntimeException('Direktori kunci tidak valid');
        }
        $tmp = tempnam($dir, '.tmp-');
        if ($tmp === false) {
            throw new \RuntimeException('Gagal membuat file sementara');
        }
        try {
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $pem, LOCK_EX) !== strlen($pem)) {
                throw new \RuntimeException('Gagal menulis kunci');
            }
            $final = $dir . '/' . $kid . '.pem';
            if (!rename($tmp, $final)) {
                throw new \RuntimeException('Gagal memindahkan kunci');
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        return $final;
    }

    /** Path hanya valid jika berada langsung di $keyDir dan berekstensi .pem. */
    private function safePath(string $path): ?string
    {
        $dir = realpath($this->keyDir);
        $real = realpath($path);
        if ($dir === false || $real === false || dirname($real) !== $dir || !str_ends_with($real, '.pem')) {
            return null;
        }

        return $real;
    }

    private function privatePem(SigningKey $key): string
    {
        if (isset($this->privateCache[$key->kid])) {
            return $this->privateCache[$key->kid];
        }
        $real = $this->safePath($key->privateKeyPath);
        if ($real === null) {
            throw new \RuntimeException('File private key tidak ditemukan');
        }
        $perms = fileperms($real);
        if ($perms === false || ($perms & 0077) !== 0) {
            throw new \RuntimeException('Izin file private key terlalu longgar (harus 600)');
        }
        $pem = file_get_contents($real);
        if ($pem === false || $pem === '') {
            throw new \RuntimeException('File private key tidak terbaca');
        }

        return $this->privateCache[$key->kid] = $pem;
    }

    private function intSetting(string $name, int $default): int
    {
        $value = $this->settings->get($name)?->value;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $default;
    }

    private static function thumbprint(string $n, string $e): string
    {
        $json = '{"e":"' . $e . '","kty":"RSA","n":"' . $n . '"}';

        return JWT::urlsafeB64Encode(hash('sha256', $json, true));
    }

    /** @return array<string,string> */
    private static function jwk(SigningKey $key): array
    {
        $pub = openssl_pkey_get_public($key->publicKeyPem);
        $d = $pub === false ? false : openssl_pkey_get_details($pub);
        if ($d === false || !isset($d['rsa']['n'], $d['rsa']['e'])) {
            throw new \RuntimeException('Public key tidak valid');
        }

        return [
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $key->kid,
            'n' => JWT::urlsafeB64Encode($d['rsa']['n']), 'e' => JWT::urlsafeB64Encode($d['rsa']['e']),
        ];
    }
}
