<?php
namespace Reactor\Cache\Drivers;

use Reactor\Contracts\CacheInterface;

class FileCache implements CacheInterface
{
    private string $path;
    private string $prefix;

    /**
     * Whether deserialization may instantiate objects.
     *
     * @var bool|array<int, string> true = allow all, false = allow none,
     *                              array = allow only the listed class names.
     */
    private bool|array $allowedClasses;

    public function __construct(string $path, string $prefix = 'reactor_', bool|array $allowedClasses = true)
    {
        $this->path = rtrim($path, '/');
        $this->prefix = $prefix;
        $this->allowedClasses = $allowedClasses;

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
        $decoded = unserialize($data, ['allowed_classes' => $this->allowedClasses]);
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
        // Unlike the previous implementation, this method now respects
        // the stored TTL. An expired file is removed and reported as
        // absent so that callers relying on has() see a consistent
        // view with get().
        $file = $this->fileName($key);
        if (!file_exists($file)) {
            return false;
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return false;
        }

        $decoded = @unserialize($data, ['allowed_classes' => $this->allowedClasses]);
        if (!is_array($decoded)) {
            return false;
        }

        if (isset($decoded['ttl']) && $decoded['ttl'] !== null && time() > $decoded['ttl']) {
            @unlink($file);
            return false;
        }

        return true;
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
        // The read-modify-write cycle runs under an exclusive file lock
        // so that concurrent increments cannot lose updates. The TTL of
        // an existing entry is preserved; an expired entry restarts
        // from zero without a TTL.
        $file = $this->fileName($key);

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $contents = stream_get_contents($handle);
            $decoded = ($contents !== false && $contents !== '')
                ? @unserialize($contents, ['allowed_classes' => $this->allowedClasses])
                : null;

            $current = 0;
            $remainingTtl = null;

            if (is_array($decoded)) {
                $storedTtl = $decoded['ttl'] ?? null;
                $notExpired = $storedTtl === null || time() <= $storedTtl;

                if ($notExpired) {
                    $current = (int) ($decoded['value'] ?? 0);
                    if ($storedTtl !== null) {
                        $remainingTtl = $storedTtl - time();
                    }
                }
            }

            $new = $current + $amount;

            $data = [
                'value' => $new,
                'ttl' => $remainingTtl !== null ? time() + $remainingTtl : null,
            ];

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, serialize($data));
            fflush($handle);

            return $new;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function decrement(string $key, int $amount = 1): int|false
    {
        return $this->increment($key, -$amount);
    }
}
