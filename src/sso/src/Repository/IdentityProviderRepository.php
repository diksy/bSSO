<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Record\IdentityProvider;
use App\Support\Database;

/** Satu class per tabel; SQL hanya di sini (PDO prepared). Metode *ForUpdate hanya di dalam transaction(). */
final class IdentityProviderRepository
{
    private const COLUMNS = 'id, slug, display_name, protocol, driver, client_id, client_secret_enc, discovery_url, authorize_url, '
        . 'token_url, userinfo_url, scopes, trust_email, extra_config, is_enabled, sort_order';

    public function __construct(private readonly Database $db)
    {
    }

    public function findBySlug(string $slug): ?IdentityProvider
    {
        return $this->one('WHERE slug = ?', [$slug]);
    }

    public function findById(int $id): ?IdentityProvider
    {
        return $this->one('WHERE id = ?', [$id]);
    }

    /** @return list<IdentityProvider> */
    public function listEnabled(): array
    {
        return $this->many('WHERE is_enabled = 1');
    }

    /** @return list<IdentityProvider> */
    public function listAll(): array
    {
        return $this->many('');
    }

    /** @param list<mixed> $params */
    private function one(string $where, array $params): ?IdentityProvider
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM identity_providers ' . $where . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<IdentityProvider> */
    private function many(string $where): array
    {
        $stmt = $this->db->pdo()->query('SELECT ' . self::COLUMNS . ' FROM identity_providers ' . $where . ' ORDER BY sort_order, id');
        if ($stmt === false) {
            return [];
        }

        return array_map(fn (array $r): IdentityProvider => $this->hydrate($r), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): IdentityProvider
    {
        $extra = is_string($r['extra_config'] ?? null) ? json_decode($r['extra_config'], true) : null;
        $opt = static fn (mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;

        return new IdentityProvider(
            (int) $r['id'],
            (string) $r['slug'],
            (string) $r['display_name'],
            (string) $r['protocol'],
            (string) $r['driver'],
            (string) $r['client_id'],
            $opt($r['client_secret_enc'] ?? null),
            $opt($r['discovery_url'] ?? null),
            $opt($r['authorize_url'] ?? null),
            $opt($r['token_url'] ?? null),
            $opt($r['userinfo_url'] ?? null),
            (string) $r['scopes'],
            (bool) $r['trust_email'],
            is_array($extra) ? $extra : [],
            (bool) $r['is_enabled'],
            (int) $r['sort_order'],
        );
    }
}
