<?php

namespace Reactor\Cache\Drivers;

use Amp\Future;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Reactor\Contracts\CacheInterface;

/**
 * Non-blocking Redis cache backed by amphp/redis.
 *
 * Connection settings are read from the configuration array:
 *   - host (default: 127.0.0.1)
 *   - port (default: 6379)
 *   - password (optional)
 *   - database (default: 0)
 *   - prefix (default: reactor:)
 *   - allowed_classes (default: true)
 */
class RedisCache implements CacheInterface
{
    private RedisClient $redis;
    private string $prefix;
    private bool|array $allowedClasses;

    public function __construct(array $config)
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;
        $this->prefix = $config['prefix'] ?? 'reactor:';
        $this->allowedClasses = $config['allowed_classes'] ?? true;

        $uri = sprintf('redis://%s:%d', $host, $port);

        $redisConfig = RedisConfig::fromUri($uri);

        if ($password !== null && $password !== '') {
            $redisConfig = $redisConfig->withPassword($password);
        }

        if ($database > 0) {
            $redisConfig = $redisConfig->withDatabase($database);
        }

        $this->redis = new RedisClient($redisConfig);
    }

    public function get(string $key, mixed $default = null): Future
    {
        return Future::complete($this->doGet($key, $default));
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return Future::complete($this->doSet($key, $value, $ttl));
    }

    public function delete(string $key): Future
    {
        return Future::complete($this->doDelete($key));
    }

    public function has(string $key): Future
    {
        return Future::complete($this->doHas($key));
    }

    public function clear(): Future
    {
        return Future::complete($this->redis->flushDatabase());
    }

    public function remember(string $key, int $ttl, callable $callback): Future
    {
        if ($this->doHas($key)) {
            return Future::complete($this->doGet($key));
        }

        $value = $callback();

        if ($value instanceof Future) {
            return $value->map(function ($resolved) use ($key, $ttl) {
                $this->doSet($key, $resolved, $ttl);
                return $resolved;
            });
        }

        $this->doSet($key, $value, $ttl);
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
                $this->doSet($key, $resolved);
                return $resolved;
            });
        }

        $this->doSet($key, $value);
        return Future::complete($value);
    }

    public function increment(string $key, int $amount = 1): Future
    {
        return Future::complete(
            $this->redis->incrementBy($this->prefix . $key, $amount)
        );
    }

    public function decrement(string $key, int $amount = 1): Future
    {
        return $this->increment($key, -$amount);
    }

    private function doGet(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->get($this->prefix . $key);

        if ($raw === null) {
            return $default;
        }

        return unserialize($raw, ['allowed_classes' => $this->allowedClasses]);
    }

    private function doSet(string $key, mixed $value, ?int $ttl = null): bool
    {
        $serialized = serialize($value);

        if ($ttl !== null && $ttl > 0) {
            $this->redis->setWithTtl($this->prefix . $key, $serialized, $ttl);
        } else {
            $this->redis->set($this->prefix . $key, $serialized);
        }

        return true;
    }

    private function doDelete(string $key): bool
    {
        $this->redis->delete($this->prefix . $key);
        return true;
    }

    private function doHas(string $key): bool
    {
        return $this->redis->exists($this->prefix . $key);
    }
}
