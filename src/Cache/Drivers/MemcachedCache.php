<?php
namespace Reactor\Cache\Drivers;

use Reactor\Contracts\CacheInterface;

class MemcachedCache implements CacheInterface
{
    private \Memcached $memcached;
    private string $prefix;

    /**
     * Whether deserialization may instantiate objects.
     *
     * @var bool|array<int, string>
     */
    private bool|array $allowedClasses;

    public function __construct(array $config)
    {
        if (!extension_loaded('memcached')) {
            throw new \RuntimeException('Memcached extension not loaded.');
        }
        $this->memcached = new \Memcached();
        $servers = $config['servers'] ?? [['host' => '127.0.0.1', 'port' => 11211]];
        $this->prefix = $config['prefix'] ?? 'reactor:';
        $this->allowedClasses = $config['allowed_classes'] ?? true;
        foreach ($servers as $server) {
            $this->memcached->addServer($server['host'], $server['port']);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($this->prefix . $key);
        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $default;
        }
        return unserialize($value, ['allowed_classes' => $this->allowedClasses]);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $serialized = serialize($value);
        $expiry = $ttl ?? 0;
        return $this->memcached->set($this->prefix . $key, $serialized, $expiry);
    }

    public function delete(string $key): bool
    {
        return $this->memcached->delete($this->prefix . $key);
    }

    public function has(string $key): bool
    {
        $this->memcached->get($this->prefix . $key);
        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    public function clear(): bool
    {
        return $this->memcached->flush();
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
        return $this->memcached->increment($this->prefix . $key, $amount);
    }

    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->memcached->decrement($this->prefix . $key, $amount);
    }
}
