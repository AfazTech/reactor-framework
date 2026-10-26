<?php

namespace Reactor\Cache;

use Amp\Future;
use Reactor\Contracts\CacheInterface;
use Reactor\Cache\Drivers\ArrayCache;
use Reactor\Cache\Drivers\FileCache;
use Reactor\Cache\Drivers\MysqlCache;
use Reactor\Cache\Drivers\PostgresCache;
use Reactor\Cache\Drivers\RedisCache;
use Reactor\Cache\Drivers\SqliteCache;

/**
 * Facade that selects the configured asynchronous cache driver.
 */
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
            'array'    => new ArrayCache(),
            'file'     => new FileCache(
                $config['path'] ?? storage_path('cache'),
                $config['prefix'] ?? 'reactor_',
                $config['allowed_classes'] ?? false
            ),
            'redis'    => new RedisCache($config),
            'mysql'    => new MysqlCache($config),
            'postgres' => new PostgresCache($config),
            'sqlite'   => new SqliteCache($config),
            default    => throw new \InvalidArgumentException(
                "Unsupported cache driver: {$driver}"
            ),
        };
    }

    public function get(string $key, mixed $default = null): Future
    {
        return $this->driver->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return $this->driver->set($key, $value, $ttl);
    }

    public function delete(string $key): Future
    {
        return $this->driver->delete($key);
    }

    public function has(string $key): Future
    {
        return $this->driver->has($key);
    }

    public function clear(): Future
    {
        return $this->driver->clear();
    }

    public function remember(string $key, int $ttl, callable $callback): Future
    {
        return $this->driver->remember($key, $ttl, $callback);
    }

    public function rememberForever(string $key, callable $callback): Future
    {
        return $this->driver->rememberForever($key, $callback);
    }

    public function increment(string $key, int $amount = 1): Future
    {
        return $this->driver->increment($key, $amount);
    }

    public function decrement(string $key, int $amount = 1): Future
    {
        return $this->driver->decrement($key, $amount);
    }
}
