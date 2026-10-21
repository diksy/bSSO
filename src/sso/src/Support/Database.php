<?php

declare(strict_types=1);

namespace App\Support;

final class Database
{
    private ?\PDO $pdo = null;
    private int $depth = 0;

    /** @param array<string,mixed> $config host, port, name, user, pass (dari config/database.php) */
    public function __construct(private readonly array $config)
    {
    }

    /** PDO dengan ERRMODE_EXCEPTION dan EMULATE_PREPARES=false. */
    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($this->config['host'] ?? '127.0.0.1'),
                (int) ($this->config['port'] ?? 3306),
                (string) ($this->config['name'] ?? ''),
            );
            $this->pdo = new \PDO($dsn, (string) ($this->config['user'] ?? ''), (string) ($this->config['pass'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00'");
        }

        return $this->pdo;
    }

    /**
     * Transaksi; pemanggilan bersarang ikut transaksi terluar.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($this->depth > 0) {
            return $fn();
        }
        $pdo->beginTransaction();
        $this->depth = 1;
        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }
}
