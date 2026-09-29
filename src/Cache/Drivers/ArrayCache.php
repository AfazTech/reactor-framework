<?php
namespace Reactor\Cache\Drivers;

use Reactor\Contracts\CacheInterface;

class ArrayCache implements CacheInterface
{
    private array $storage = [];
    private array $expirations = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->clean($key);
        return $this->storage[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->storage[$key] = $value;
        if ($ttl) {
            $this->expirations[$key] = time() + $ttl;
        }
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key], $this->expirations[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        $this->clean($key);
        return isset($this->storage[$key]);
    }

    public function clear(): bool
    {
        $this->storage = [];
        $this->expirations = [];
        return true;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->set($key, $value);
        return $value;
    }

    public function increment(string $key, int $amount = 1): int|false
    {
        $current = (int) $this->get($key, 0);
        $new = $current + $amount;
        $this->set($key, $new);
        return $new;
    }

    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->increment($key, -$amount);
    }

    private function clean(string $key): void
    {
        if (isset($this->expirations[$key]) && time() > $this->expirations[$key]) {
            unset($this->storage[$key], $this->expirations[$key]);
        }
    }
}
