<?php

namespace Reactor\Cache\Drivers;

use Amp\Future;
use Amp\Redis\RedisClient;
use Reactor\Contracts\CacheInterface;
use function Amp\Redis\createRedisClient;

/**
 * Non-blocking Redis cache backed by amphp/redis.
 */
class RedisCache implements CacheInterface
{
    private RedisClient $redis;
    private string $prefix;
    private bool|array $allowedClasses;

    public function __construct(array $config)
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $password = $config['password'] ?? null;
        $database = (int) ($config['database'] ?? 0);
        $this->prefix = (string) ($config['prefix'] ?? 'reactor:');
        $this->allowedClasses = $config['allowed_classes'] ?? false;

        $uri = sprintf('redis://%s:%d', $host, $port);

        if (is_string($password) && $password !== '') {
            $uri = sprintf('redis://:%s@%s:%d', rawurlencode($password), $host, $port);
        }

        if ($database > 0) {
            $uri .= '/' . $database;
        }

        $this->redis = createRedisClient($uri);
    }

    public function get(string $key, mixed $default = null): Future
    {
        $raw = $this->redis->get($this->prefix . $key);

        if ($raw === null) {
            return Future::complete($default);
        }

        return Future::complete(
            unserialize($raw, ['allowed_classes' => $this->allowedClasses])
        );
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        $redisKey = $this->prefix . $key;
        $this->redis->set($redisKey, serialize($value));

        if ($ttl !== null && $ttl > 0) {
            $this->redis->expireIn($redisKey, $ttl);
        }

        return Future::complete(true);
    }

    public function delete(string $key): Future
    {
        $this->redis->delete($this->prefix . $key);
        return Future::complete(true);
    }

    public function has(string $key): Future
    {
        return Future::complete($this->redis->has($this->prefix . $key));
    }

    public function clear(): Future
    {
        // Only remove keys that belong to this cache instance. Using
        // flushDatabase() would also wipe unrelated keys that share the
        // same Redis database.
        foreach ($this->redis->scan($this->prefix . '*') as $key) {
            $this->redis->delete($key);
        }

        return Future::complete(true);
    }

    public function remember(string $key, int $ttl, callable $callback): Future
    {
        if ($this->redis->has($this->prefix . $key)) {
            return $this->get($key);
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
        if ($this->redis->has($this->prefix . $key)) {
            return $this->get($key);
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
        return Future::complete(
            $this->redis->increment($this->prefix . $key, $amount)
        );
    }

    public function decrement(string $key, int $amount = 1): Future
    {
        return Future::complete(
            $this->redis->decrement($this->prefix . $key, $amount)
        );
    }
}
