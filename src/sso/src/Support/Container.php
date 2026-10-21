<?php

declare(strict_types=1);

namespace App\Support;

final class Container
{
    /** @var array<string,mixed> */
    private array $entries = [];
    /** @var array<string,true> */
    private array $factories = [];
    /** @var array<string,true> */
    private array $resolving = [];

    /** @param mixed $factoryOrValue Closure(Container): mixed dibuat lazy dan di-cache */
    public function set(string $id, mixed $factoryOrValue): void
    {
        unset($this->factories[$id]);
        $this->entries[$id] = $factoryOrValue;
        if ($factoryOrValue instanceof \Closure) {
            $this->factories[$id] = true;
        }
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new \RuntimeException('Layanan tidak terdaftar: ' . $id);
        }
        if (isset($this->factories[$id])) {
            if (isset($this->resolving[$id])) {
                throw new \LogicException('Dependensi melingkar: ' . $id);
            }
            $this->resolving[$id] = true;
            try {
                /** @var \Closure $factory */
                $factory = $this->entries[$id];
                $this->entries[$id] = $factory($this);
                unset($this->factories[$id]);
            } finally {
                unset($this->resolving[$id]);
            }
        }

        return $this->entries[$id];
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
        if (!class_exists($class)) {
            throw new \RuntimeException('Class tidak ada: ' . $class);
        }
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $name = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
            if ($name !== null && $this->has($name)) {
                $params[] = $this->get($name);
            } elseif (
                $name !== null && !(($args[0] ?? null) instanceof $name) && class_exists($name)
                && (new \ReflectionClass($name))->isInstantiable()
            ) {
                $params[] = $this->make($name);
            } elseif ($args !== []) {
                $params[] = array_shift($args);
            } elseif ($param->isDefaultValueAvailable()) {
                $params[] = $param->getDefaultValue();
            } else {
                throw new \RuntimeException('Argumen tidak terpenuhi: ' . $class . '::$' . $param->getName());
            }
        }

        return new $class(...$params);
    }
}
