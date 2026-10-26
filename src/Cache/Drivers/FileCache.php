<?php

namespace Reactor\Cache\Drivers;

use Amp\File;
use Amp\Future;
use Amp\Sync\LocalKeyedMutex;
use Reactor\Contracts\CacheInterface;

/**
 * Non-blocking file-system cache backed by amphp/file.
 *
 * Values are serialized with PHP's serialize(). The allowed_classes
 * option defaults to false, which prevents PHP Object Injection when a
 * cache file is tampered with.
 *
 * increment() / decrement() are atomic within a single process only.
 * Cross-process atomicity is not provided by this driver.
 */
class FileCache implements CacheInterface
{
    private string $path;
    private string $prefix;
    private bool|array $allowedClasses;
    private LocalKeyedMutex $mutex;

    public function __construct(
        string $path,
        string $prefix = 'reactor_',
        bool|array $allowedClasses = false
    ) {
        $this->path = rtrim($path, '/');
        $this->prefix = $this->normalizePrefix($prefix);
        $this->allowedClasses = $allowedClasses;
        $this->mutex = new LocalKeyedMutex();
    }

    public function get(string $key, mixed $default = null): Future
    {
        $file = $this->fileName($key);

        if (!File\exists($file)) {
            return Future::complete($default);
        }

        $raw = File\read($file);
        $decoded = @unserialize($raw, ['allowed_classes' => $this->allowedClasses]);

        if (!is_array($decoded)) {
            return Future::complete($default);
        }

        $ttl = $decoded['ttl'] ?? null;
        if ($ttl !== null && time() > $ttl) {
            File\delete($file);
            return Future::complete($default);
        }

        return Future::complete($decoded['value'] ?? $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        $this->ensureDirectory();

        File\write($this->fileName($key), serialize([
            'value' => $value,
            'ttl'   => $ttl !== null && $ttl > 0 ? time() + $ttl : null,
        ]));

        return Future::complete(true);
    }

    public function delete(string $key): Future
    {
        $file = $this->fileName($key);

        if (File\exists($file)) {
            File\delete($file);
        }

        return Future::complete(true);
    }

    public function has(string $key): Future
    {
        $file = $this->fileName($key);

        if (!File\exists($file)) {
            return Future::complete(false);
        }

        $raw = File\read($file);
        $decoded = @unserialize($raw, ['allowed_classes' => $this->allowedClasses]);

        if (!is_array($decoded)) {
            return Future::complete(false);
        }

        $ttl = $decoded['ttl'] ?? null;
        if ($ttl !== null && time() > $ttl) {
            File\delete($file);
            return Future::complete(false);
        }

        return Future::complete(true);
    }

    public function clear(): Future
    {
        if (!File\isDirectory($this->path)) {
            return Future::complete(true);
        }

        foreach (File\listFiles($this->path) as $file) {
            $name = basename($file);
            if (str_starts_with($name, $this->prefix)) {
                File\delete($file);
            }
        }

        return Future::complete(true);
    }

    public function remember(string $key, int $ttl, callable $callback): Future
    {
        if ($this->has($key)->await()) {
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
        if ($this->has($key)->await()) {
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
        $file = $this->fileName($key);
        $mutex = $this->mutex->acquire($key);

        try {
            $current = 0;
            $remainingTtl = null;

            if (File\exists($file)) {
                $raw = File\read($file);
                $decoded = @unserialize($raw, ['allowed_classes' => $this->allowedClasses]);

                if (is_array($decoded)) {
                    $ttl = $decoded['ttl'] ?? null;
                    if ($ttl === null || time() <= $ttl) {
                        $current = (int) ($decoded['value'] ?? 0);
                        if ($ttl !== null) {
                            $remainingTtl = $ttl - time();
                        }
                    }
                }
            }

            $new = $current + $amount;

            $this->ensureDirectory();
            File\write($file, serialize([
                'value' => $new,
                'ttl'   => $remainingTtl !== null ? time() + $remainingTtl : null,
            ]));

            return Future::complete($new);
        } finally {
            $mutex->release();
        }
    }

    public function decrement(string $key, int $amount = 1): Future
    {
        return $this->increment($key, -$amount);
    }

    private function fileName(string $key): string
    {
        return $this->path . '/' . $this->prefix . md5($key) . '.cache';
    }

    private function ensureDirectory(): void
    {
        if (!File\isDirectory($this->path)) {
            File\createDirectoryRecursively($this->path, 0755);
        }
    }

    private function normalizePrefix(string $prefix): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_.-]/', '_', $prefix);

        return $normalized === '' ? 'reactor_' : $normalized;
    }
}
