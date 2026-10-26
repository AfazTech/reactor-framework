<?php

namespace Reactor\Contracts;

use Amp\Future;

/**
 * Asynchronous cache contract.
 *
 * Every method returns an Amp\Future. Callers must await the result
 * inside a fiber or inside an Amp event-loop callback.
 */
interface CacheInterface
{
    /**
     * Retrieve a value from the cache.
     *
     * @return Future<mixed>
     */
    public function get(string $key, mixed $default = null): Future;

    /**
     * Store a value in the cache.
     *
     * @param int|null $ttl Time-to-live in seconds; null means forever.
     * @return Future<bool>
     */
    public function set(string $key, mixed $value, ?int $ttl = null): Future;

    /**
     * Remove a value from the cache.
     *
     * @return Future<bool>
     */
    public function delete(string $key): Future;

    /**
     * Determine whether a key exists and has not expired.
     *
     * @return Future<bool>
     */
    public function has(string $key): Future;

    /**
     * Remove every entry managed by this cache instance.
     *
     * @return Future<bool>
     */
    public function clear(): Future;

    /**
     * Retrieve a value or compute and store it when missing.
     *
     * @return Future<mixed>
     */
    public function remember(string $key, int $ttl, callable $callback): Future;

    /**
     * Retrieve a value or compute and store it forever when missing.
     *
     * @return Future<mixed>
     */
    public function rememberForever(string $key, callable $callback): Future;

    /**
     * Atomically increment an integer value.
     *
     * @return Future<int|false>
     */
    public function increment(string $key, int $amount = 1): Future;

    /**
     * Atomically decrement an integer value.
     *
     * @return Future<int|false>
     */
    public function decrement(string $key, int $amount = 1): Future;
}
