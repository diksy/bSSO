<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Menggabungkan konfigurasi per kunci. Prioritas: env server > .env > config/*.php > default.
 * (.env sudah dimuat ke environment proses oleh DotEnv tanpa menimpa env server.)
 * Nilai env string kosong dianggap TIDAK di-set (tidak menimpa config).
 */
final class ConfigLoader
{
    /** @var callable(string):(string|false) */
    private $env;

    /** @param (callable(string):(string|false))|null $env sumber env (default getenv) */
    public function __construct(private string $configDir, ?callable $env = null)
    {
        $this->env = $env ?? static fn (string $k): string|false => getenv($k);
    }

    private function env(string $key): ?string
    {
        $v = ($this->env)($key);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** @return array<string,mixed> */
    private function file(string $name): array
    {
        $f = rtrim($this->configDir, '/') . '/' . $name . '.php';

        return is_file($f) ? (array) require $f : [];
    }

    /** @return array<string,mixed> */
    public function app(): array
    {
        $c = $this->file('app') + [
            'debug' => false, 'mail_from' => '', 'base_url' => '', 'key_dir' => '', 'trusted_proxies' => [],
        ];
        foreach (['APP_BASE_URL' => 'base_url', 'MAIL_FROM' => 'mail_from', 'KEY_DIR' => 'key_dir'] as $k => $name) {
            $c[$name] = $this->env($k) ?? $c[$name];
        }
        $debug = $this->env('APP_DEBUG');
        if ($debug !== null) {
            $c['debug'] = self::bool($debug, (bool) $c['debug']);
        }
        $proxies = $this->env('TRUSTED_PROXIES');
        if ($proxies !== null) {
            $c['trusted_proxies'] = array_values(array_filter(
                array_map('trim', explode(',', $proxies)),
                static fn (string $p): bool => $p !== '',
            ));
        }

        return $c;
    }

    /** @return array<string,mixed> */
    public function database(): array
    {
        $c = $this->file('database') + ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'bikinin_sso', 'user' => '', 'pass' => ''];
        foreach (['DB_HOST' => 'host', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASS' => 'pass'] as $k => $name) {
            $c[$name] = $this->env($k) ?? $c[$name];
        }
        $port = $this->env('DB_PORT');
        if ($port !== null && ctype_digit($port)) {
            $c['port'] = (int) $port;
        }
        $c['port'] = (int) $c['port'];

        return $c;
    }

    /** @return array<string,mixed> */
    public function secrets(): array
    {
        $c = $this->file('secrets');
        $hmac = $this->env('HMAC_KEY') ?? (string) ($c['hmac_key'] ?? '');
        $ring = (array) ($c['keyring'] ?? []);
        $keys = (array) ($ring['keys'] ?? []);
        $current = (string) ($ring['current'] ?? '');

        $envKeys = $this->env('ENCRYPTION_KEYS');
        if ($envKeys !== null) {
            foreach (explode(',', $envKeys) as $pair) {
                $pos = strpos($pair, ':'); // base64 boleh memuat '=', pisah di ':' pertama
                $id = $pos === false ? '' : trim(substr($pair, 0, $pos));
                $val = $pos === false ? '' : trim(substr($pair, $pos + 1));
                if ($id !== '' && $val !== '') {
                    $keys[$id] = $val;
                }
            }
        }
        $current = $this->env('ENCRYPTION_KEY_CURRENT') ?? $current;

        $keys = array_filter($keys, static fn ($v): bool => is_string($v) && $v !== '');
        if ($hmac === '' || $current === '' || !isset($keys[$current])) {
            throw new RuntimeException(
                'Rahasia belum lengkap: set HMAC_KEY, ENCRYPTION_KEY_CURRENT dan ENCRYPTION_KEYS (di .env atau env server), atau config/secrets.php',
            );
        }

        return ['hmac_key' => $hmac, 'keyring' => ['current' => $current, 'keys' => $keys]];
    }

    private static function bool(string $v, bool $default): bool
    {
        return match (strtolower($v)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }
}
