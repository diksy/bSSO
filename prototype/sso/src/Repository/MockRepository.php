<?php
declare(strict_types=1);

namespace App\Repository;

/** Satu-satunya lapisan yang membaca prototype/mocks (pengganti repository DB). */
final class MockRepository
{
    private static array $cache = [];

    private function load(string $name): array
    {
        return self::$cache[$name] ??= require MOCKS_PATH . '/' . $name . '.php';
    }

    public function users(): array { return $this->load('users'); }
    public function user(int $id): ?array { return $this->users()[$id] ?? null; }

    public function userByEmail(string $email): ?array
    {
        foreach ($this->users() as $u) {
            if (strcasecmp($u['email'], $email) === 0) {
                return $u;
            }
        }
        return null;
    }

    public function clients(): array { return $this->load('clients'); }

    public function client(string $id): ?array
    {
        foreach ($this->clients() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    public function providers(): array
    {
        $p = $this->load('providers');
        usort($p, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        return $p;
    }

    public function provider(string $slug): ?array
    {
        foreach ($this->providers() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    public function activeProviders(): array
    {
        return array_values(array_filter($this->providers(), static fn (array $p): bool => $p['active']));
    }

    public function sessions(): array { return $this->load('sessions'); }
    public function audit(): array { return $this->load('audit_logs'); }
    public function keys(): array { return $this->load('keys'); }
    public function settings(): array { return $this->load('settings'); }
    public function dashboard(): array { return $this->load('dashboard'); }
    public function roles(): array { return $this->load('roles'); }
}
