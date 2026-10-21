<?php

declare(strict_types=1);

namespace App\Support;

final class Database
{
    /** @param array<string,mixed> $config host, port, name, user, pass (dari config/database.php) */
    public function __construct(private readonly array $config)
    {
    }

    /** PDO dengan ERRMODE_EXCEPTION dan EMULATE_PREPARES=false. */
    public function pdo(): \PDO
    {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        throw new \LogicException('belum diimplementasi');
    }
}
