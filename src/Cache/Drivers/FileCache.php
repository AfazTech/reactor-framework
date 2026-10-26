<?php

namespace Reactor\Cache\Drivers;

use Amp\File;
use Amp\File\LockType;
use Amp\Future;
use Reactor\Contracts\CacheInterface;
use function Amp\async;

/**
 * Non-blocking file-system cache backed by amphp/file.
 *
 * Each key is stored in its own file under the configured directory.
 * Values are serialized with PHP's serialize() so that arbitrary
 * serializable values can be cached.
 *
 * increment() and decrement() take an exclusive flock() on the file
 * through amphp/file, which makes them safe across multiple processes.
 */
class FileCache implements CacheInterface
{
    private string $path;
    private string $prefix;
    private bool|array $allowedClasses;

    public function __construct(
        string $path,
        string $prefix = 'reactor_',
        bool|array $allowedClasses = false
    ) {
        $this->path = rtrim($path, '/');
        $this->prefix = $this->normalizePrefix($prefix);
        $this->allowedClasses = $allowedClasses;
    }

    public function get(string $key, mixed $default = null): Future
    {
        return async(fn(): mixed => $this->doGet($key, $default));
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(fn(): bool => $this->doSet($key, $value, $ttl));
    }

    public function delete(string $key): Future
    {
        return async(fn(): bool => $this->doDelete($key));
    }

    public function has(string $key): Future
    {
        return async(fn(): bool => $this->doHas($key));
    }

    public function clear(): Future
    {
        return async(fn(): bool => $this->doClear());
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
        return async(fn(): int => $this->doIncrement($key, $amount));
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
            File\deleteFile($file);
            return $default;
        }

        // array_key_exists() is used instead of ?? so that a cached null
        // value is returned as null, not replaced by $default.
        return array_key_exists('value', $decoded) ? $decoded['value'] : $default;
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

        if (File\exists($file)) {
            File\deleteFile($file);
        }

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
            File\deleteFile($file);
            return false;
        }

        return true;
    }

    private function doClear(): bool
    {
        if (!File\isDirectory($this->path)) {
            return true;
        }

        foreach (File\listFiles($this->path) as $filename) {
            if (str_starts_with($filename, $this->prefix)) {
                File\deleteFile($this->path . '/' . $filename);
            }
        }

        return true;
    }

    private function doIncrement(string $key, int $amount): int
    {
        if (!File\isDirectory($this->path)) {
            File\createDirectoryRecursively($this->path, 0755);
        }

        $file = $this->fileName($key);

        $handle = File\openFile($file, 'c+');

        try {
            $handle->lock(LockType::Exclusive);

            // File::read() returns null at EOF; loop until exhausted.
            // The cache payload is small enough that this typically
            // completes in one or two reads.
            $raw = '';
            while (($chunk = $handle->read()) !== null) {
                $raw .= $chunk;
            }

            $current = 0;
            $remainingTtl = null;

            if ($raw !== '') {
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

            $handle->seek(0);
            $handle->truncate(0);
            $handle->seek(0);
            $handle->write(serialize($data));

            return $new;
        } finally {
            $handle->unlock();
            $handle->close();
        }
    }

    private function normalizePrefix(string $prefix): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_.-]/', '_', $prefix);

        return $normalized === '' ? 'reactor_' : $normalized;
    }
}
