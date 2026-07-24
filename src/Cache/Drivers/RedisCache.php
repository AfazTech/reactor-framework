<?php
namespace Reactor\Cache\Drivers;

use Reactor\Contracts\CacheInterface;

class RedisCache implements CacheInterface
{
    private \Redis $redis;
    private string $prefix;

    public function __construct(array $config)
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('Redis extension not loaded.');
        }
        $this->redis = new \Redis();
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;
        $this->prefix = $config['prefix'] ?? 'reactor:';
        if (!$this->redis->connect($host, $port)) {
            throw new \RuntimeException("Could not connect to Redis at {$host}:{$port}");
        }
        if ($password) {
            $this->redis->auth($password);
        }
        $this->redis->select($database);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($this->prefix . $key);
        return $value !== false ? unserialize($value) : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $serialized = serialize($value);
        if ($ttl === null) {
            return $this->redis->set($this->prefix . $key, $serialized);
        }
        return $this->redis->setex($this->prefix . $key, $ttl, $serialized);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($this->prefix . $key) > 0;
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($this->prefix . $key) > 0;
    }

    public function clear(): bool
    {
        return $this->redis->flushDB();
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
        return $this->redis->incrBy($this->prefix . $key, $amount);
    }

    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->redis->decrBy($this->prefix . $key, $amount);
    }
}
