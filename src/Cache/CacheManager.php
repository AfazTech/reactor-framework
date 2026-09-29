<?php
namespace Reactor\Cache;

use Reactor\Contracts\CacheInterface;
use Reactor\Cache\Drivers\RedisCache;
use Reactor\Cache\Drivers\MemcachedCache;
use Reactor\Cache\Drivers\FileCache;
use Reactor\Cache\Drivers\ArrayCache;

class CacheManager implements CacheInterface
{
    private CacheInterface $driver;

    public function __construct(array $config)
    {
        $this->driver = $this->createDriver($config);
    }

    private function createDriver(array $config): CacheInterface
    {
        $driver = $config['driver'] ?? 'file';
        return match ($driver) {
            'redis' => new RedisCache($config),
            'memcached' => new MemcachedCache($config),
            'file' => new FileCache(
                $config['path'] ?? storage_path('cache'),
                $config['prefix'] ?? 'reactor_',
                $config['allowed_classes'] ?? true
            ),
            'array' => new ArrayCache(),
            default => throw new \InvalidArgumentException("Unsupported cache driver: {$driver}"),
        };
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->driver->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->driver->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->driver->delete($key);
    }

    public function has(string $key): bool
    {
        return $this->driver->has($key);
    }

    public function clear(): bool
    {
        return $this->driver->clear();
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->driver->remember($key, $ttl, $callback);
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->driver->rememberForever($key, $callback);
    }

    public function increment(string $key, int $amount = 1): int|false
    {
        return $this->driver->increment($key, $amount);
    }

    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->driver->decrement($key, $amount);
    }
}
