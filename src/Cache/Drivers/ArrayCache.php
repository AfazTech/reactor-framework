<?php

namespace Reactor\Cache\Drivers;

use Amp\Future;
use Reactor\Contracts\CacheInterface;

/**
 * In-process array cache.
 *
 * Suitable for tests and short-lived requests. Data is not shared
 * across processes, so it must not be used as a production cache for
 * multi-process deployments.
 */
class ArrayCache implements CacheInterface
{
    private array $storage = [];
    private array $expirations = [];

    public function get(string $key, mixed $default = null): Future
    {
        return Future::complete($this->doGet($key, $default));
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        $this->storage[$key] = $value;
        if ($ttl !== null && $ttl > 0) {
            $this->expirations[$key] = time() + $ttl;
        } else {
            unset($this->expirations[$key]);
        }
        return Future::complete(true);
    }

    public function delete(string $key): Future
    {
        unset($this->storage[$key], $this->expirations[$key]);
        return Future::complete(true);
    }

    public function has(string $key): Future
    {
        $this->clean($key);
        return Future::complete(array_key_exists($key, $this->storage));
    }

    public function clear(): Future
    {
        $this->storage = [];
        $this->expirations = [];
        return Future::complete(true);
    }

    public function remember(string $key, int $ttl, callable $callback): Future
    {
        if ($this->doHas($key)) {
            return Future::complete($this->doGet($key));
        }
        $value = $callback();
        if ($value instanceof Future) {
            return $value->map(function ($resolved) use ($key, $ttl) {
                $this->set($key, $resolved, $ttl);
                return $resolved;
            });
        }
        $this->set($key, $value, $ttl);
        return Future::complete($value);
    }

    public function rememberForever(string $key, callable $callback): Future
    {
        if ($this->doHas($key)) {
            return Future::complete($this->doGet($key));
        }
        $value = $callback();
        if ($value instanceof Future) {
            return $value->map(function ($resolved) use ($key) {
                $this->set($key, $resolved);
                return $resolved;
            });
        }
        $this->set($key, $value);
        return Future::complete($value);
    }

    public function increment(string $key, int $amount = 1): Future
    {
        $this->clean($key);
        $current = (int) ($this->storage[$key] ?? 0);
        $new = $current + $amount;

        $remainingTtl = null;
        if (isset($this->expirations[$key])) {
            $remaining = $this->expirations[$key] - time();
            if ($remaining > 0) {
                $remainingTtl = $remaining;
            }
        }

        $this->storage[$key] = $new;
        if ($remainingTtl !== null) {
            $this->expirations[$key] = time() + $remainingTtl;
        } else {
            unset($this->expirations[$key]);
        }

        return Future::complete($new);
    }

    public function decrement(string $key, int $amount = 1): Future
    {
        return $this->increment($key, -$amount);
    }

    private function doGet(string $key, mixed $default = null): mixed
    {
        $this->clean($key);
        return $this->storage[$key] ?? $default;
    }

    private function doHas(string $key): bool
    {
        $this->clean($key);
        return array_key_exists($key, $this->storage);
    }

    private function clean(string $key): void
    {
        if (isset($this->expirations[$key]) && time() > $this->expirations[$key]) {
            unset($this->storage[$key], $this->expirations[$key]);
        }
    }
}
