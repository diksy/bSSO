<?php

declare(strict_types=1);

namespace App\Support;

final class Container
{
    /** @var array<string,mixed> */
    private array $entries = [];

    /** @param mixed $factoryOrValue Closure(Container): mixed dibuat lazy dan di-cache */
    public function set(string $id, mixed $factoryOrValue): void
    {
        throw new \LogicException('belum diimplementasi');
    }

    public function get(string $id): mixed
    {
        throw new \LogicException('belum diimplementasi');
    }

    /**
     * Buat instance baru (tidak di-cache): dependensi layanan lebih dulu, $args (argumen rute) terakhir.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param list<mixed> $args
     * @return T
     */
    public function make(string $class, array $args = []): object
    {
        throw new \LogicException('belum diimplementasi');
    }
}
