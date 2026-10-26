<?php

namespace Reactor\Cache\Drivers;

use Amp\File;
use Amp\Future;
use Amp\Sync\LocalKeyedMutex;
use Reactor\Contracts\CacheInterface;

/**
 * Non-blocking file-system cache backed by amphp/file.
 *
 * Each key is stored in its own file under the configured directory.
 * Values are serialized with PHP's serialize() so that arbitrary
 * serializable values can be cached.
 *
 * Cross-process atomicity for increment() / decrement() is not
 * guaranteed: the read-modify-write cycle is protected by an
 * in-process mutex only. Use Redis, MySQL or PostgreSQL when atomic
 * counters across multiple workers are required.
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
        bool|array $allowedClasses = true
    ) {
        $this->path = rtrim($path, '/');
        $this->prefix = $prefix;
        $this->allowedClasses = $allowedClasses;
        $this->mutex = new LocalKeyedMutex();
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
        return Future::complete($this->doClear());
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
        return Future::complete($this->doIncrement($key, $amount));
    }

    public function decrement(string $key, int $amount = 1): Future
    {
        return $this->increment($key, -$amount);
    }

    private function fileName(string $key): string
    {
        return $this->path . '/' . $this->prefix . md5($key) . '.cache';
    }

    private function doGet(string $key, mixed $default = null): mixed
    {
        $file = $this->fileName($key);

        if (!File\exists($file)) {
            return $default;
        }

        $raw = File\read($file);
        $decoded = @unserialize($raw, ['allowed_classes' => $this->allowedClasses]);

        if (!is_array($decoded)) {
            return $default;
        }

        $ttl = $decoded['ttl'] ?? null;
        if ($ttl !== null && time() > $ttl) {
            File\delete($file);
            return $default;
        }

        return $decoded['value'] ?? $default;
    }

    private function doSet(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!File\isDirectory($this->path)) {
            File\createDirectoryRecursively($this->path, 0755);
        }

        $data = [
            'value' => $value,
            'ttl'   => $ttl !== null && $ttl > 0 ? time() + $ttl : null,
        ];

        File\write($this->fileName($key), serialize($data));

        return true;
    }

    private function doDelete(string $key): bool
    {
        $file = $this->fileName($key);

        if (!File\exists($file)) {
            return true;
        }

        File\delete($file);
        return true;
    }

    private function doHas(string $key): bool
    {
        $file = $this->fileName($key);

        if (!File\exists($file)) {
            return false;
        }

        $raw = File\read($file);
        $decoded = @unserialize($raw, ['allowed_classes' => $this->allowedClasses]);

        if (!is_array($decoded)) {
            return false;
        }

        $ttl = $decoded['ttl'] ?? null;
        if ($ttl !== null && time() > $ttl) {
            File\delete($file);
            return false;
        }

        return true;
    }

    private function doClear(): bool
    {
        if (!File\isDirectory($this->path)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->path,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_starts_with($file->getFilename(), $this->prefix)) {
                File\delete($file->getPathname());
            }
        }

        return true;
    }

    private function doIncrement(string $key, int $amount): int
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

            $data = [
                'value' => $new,
                'ttl'   => $remainingTtl !== null ? time() + $remainingTtl : null,
            ];

            if (!File\isDirectory($this->path)) {
                File\createDirectoryRecursively($this->path, 0755);
            }

            File\write($file, serialize($data));

            return $new;
        } finally {
            $mutex->release();
        }
    }
}
