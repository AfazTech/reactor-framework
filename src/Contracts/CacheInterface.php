<?php
namespace Reactor\Contracts;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function clear(): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function rememberForever(string $key, callable $callback): mixed;
    public function increment(string $key, int $amount = 1): int|false;
    public function decrement(string $key, int $amount = 1): int|false;
}
