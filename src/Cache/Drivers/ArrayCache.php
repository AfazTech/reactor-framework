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

        // array_key_exists() is used instead of isset() so that a cached
        // null value is still reported as present.
        return array_key_exists($key, $this->storage);
    }

    public function clear(): bool
    {
        $this->storage = [];
        $this->expirations = [];
        return true;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        // has() is used instead of get() !== null so that a cached null
        // value is not mistaken for a cache miss.
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->set($key, $value);
        return $value;
    }

    public function increment(string $key, int $amount = 1): int|false
    {
        $this->clean($key);

        $current = (int) ($this->storage[$key] ?? 0);
        $new = $current + $amount;

        // Preserve any existing TTL so that incrementing a TTL-bound
        // counter does not silently turn it into a permanent one.
        $remainingTtl = null;
        if (isset($this->expirations[$key])) {
            $remaining = $this->expirations[$key] - time();
            if ($remaining > 0) {
                $remainingTtl = $remaining;
            }
        }

        $this->set($key, $new, $remainingTtl);
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
