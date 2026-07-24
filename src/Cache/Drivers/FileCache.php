<?php
namespace Reactor\Cache\Drivers;

use Reactor\Contracts\CacheInterface;

class FileCache implements CacheInterface
{
    private string $path;
    private string $prefix;

    public function __construct(string $path, string $prefix = 'reactor_')
    {
        $this->path = rtrim($path, '/');
        $this->prefix = $prefix;
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    private function fileName(string $key): string
    {
        return $this->path . '/' . $this->prefix . md5($key) . '.cache';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->fileName($key);
        if (!file_exists($file)) {
            return $default;
        }
        $data = file_get_contents($file);
        $decoded = unserialize($data);
        if (isset($decoded['ttl']) && $decoded['ttl'] !== null && time() > $decoded['ttl']) {
            unlink($file);
            return $default;
        }
        return $decoded['value'] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $data = [
            'value' => $value,
            'ttl' => $ttl ? time() + $ttl : null,
        ];
        return file_put_contents($this->fileName($key), serialize($data)) !== false;
    }

    public function delete(string $key): bool
    {
        $file = $this->fileName($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return file_exists($this->fileName($key));
    }

    public function clear(): bool
    {
        $files = glob($this->path . '/' . $this->prefix . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
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
}
