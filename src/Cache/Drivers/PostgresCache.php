<?php

namespace Reactor\Cache\Drivers;

use Amp\Future;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Reactor\Contracts\CacheInterface;

/**
 * Non-blocking PostgreSQL cache backed by amphp/postgres.
 *
 * The cache table is created on first use:
 *   CREATE TABLE IF NOT EXISTS cache (
 *       key VARCHAR(255) PRIMARY KEY,
 *       value TEXT NOT NULL,
 *       expires_at BIGINT NULL
 *   )
 */
class PostgresCache implements CacheInterface
{
    private PostgresConnectionPool $pool;
    private string $table;
    private bool|array $allowedClasses;
    private bool $tableEnsured = false;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'host=%s user=%s password=%s dbname=%s port=%d',
            $config['host'] ?? '127.0.0.1',
            $config['username'] ?? 'postgres',
            $config['password'] ?? '',
            $config['database'] ?? 'reactor',
            $config['port'] ?? 5432
        );

        $postgresConfig = PostgresConfig::fromString($dsn);
        $this->pool = new PostgresConnectionPool($postgresConfig);
        $this->table = $config['table'] ?? 'cache';
        $this->allowedClasses = $config['allowed_classes'] ?? true;
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
        $this->ensureTable();
        $this->pool->execute("DELETE FROM \"{$this->table}\"");
        return Future::complete(true);
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

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->pool->execute(
            "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (
                \"key\" VARCHAR(255) PRIMARY KEY,
                \"value\" TEXT NOT NULL,
                \"expires_at\" BIGINT NULL
            )"
        );

        $this->tableEnsured = true;
    }

    private function doGet(string $key, mixed $default = null): mixed
    {
        $this->ensureTable();

        $result = $this->pool->execute(
            "SELECT \"value\", \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = :key",
            ['key' => $key]
        );

        $row = $result->fetchRow();
        if ($row === null) {
            return $default;
        }

        $expiresAt = $row['expires_at'] !== null ? (int) $row['expires_at'] : null;
        if ($expiresAt !== null && time() > $expiresAt) {
            $this->doDelete($key);
            return $default;
        }

        return unserialize($row['value'], ['allowed_classes' => $this->allowedClasses]);
    }

    private function doSet(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureTable();

        $expiresAt = $ttl !== null && $ttl > 0 ? time() + $ttl : null;
        $serialized = serialize($value);

        $this->pool->execute(
            "INSERT INTO \"{$this->table}\" (\"key\", \"value\", \"expires_at\")
             VALUES (:key, :value, :expires_at)
             ON CONFLICT (\"key\") DO UPDATE
             SET \"value\" = EXCLUDED.\"value\", \"expires_at\" = EXCLUDED.\"expires_at\"",
            [
                'key'        => $key,
                'value'      => $serialized,
                'expires_at' => $expiresAt,
            ]
        );

        return true;
    }

    private function doDelete(string $key): bool
    {
        $this->ensureTable();
        $this->pool->execute(
            "DELETE FROM \"{$this->table}\" WHERE \"key\" = :key",
            ['key' => $key]
        );
        return true;
    }

    private function doHas(string $key): bool
    {
        $this->ensureTable();

        $result = $this->pool->execute(
            "SELECT \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = :key",
            ['key' => $key]
        );

        $row = $result->fetchRow();
        if ($row === null) {
            return false;
        }

        $expiresAt = $row['expires_at'] !== null ? (int) $row['expires_at'] : null;
        if ($expiresAt !== null && time() > $expiresAt) {
            $this->doDelete($key);
            return false;
        }

        return true;
    }

    private function doIncrement(string $key, int $amount): int
    {
        $this->ensureTable();

        $this->pool->transaction(function (PostgresConnectionPool $pool) use ($key, $amount) {
            $result = $pool->execute(
                "SELECT \"value\", \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = :key FOR UPDATE",
                ['key' => $key]
            );

            $row = $result->fetchRow();
            $current = 0;
            $expiresAt = null;

            if ($row !== null) {
                $storedExpiry = $row['expires_at'] !== null ? (int) $row['expires_at'] : null;
                if ($storedExpiry === null || time() <= $storedExpiry) {
                    $current = (int) unserialize($row['value'], ['allowed_classes' => false]);
                    $expiresAt = $storedExpiry;
                }
            }

            $new = $current + $amount;

            $pool->execute(
                "INSERT INTO \"{$this->table}\" (\"key\", \"value\", \"expires_at\")
                 VALUES (:key, :value, :expires_at)
                 ON CONFLICT (\"key\") DO UPDATE
                 SET \"value\" = EXCLUDED.\"value\", \"expires_at\" = EXCLUDED.\"expires_at\"",
                [
                    'key'        => $key,
                    'value'      => serialize($new),
                    'expires_at' => $expiresAt,
                ]
            );

            return $new;
        });

        $result = $this->pool->execute(
            "SELECT \"value\" FROM \"{$this->table}\" WHERE \"key\" = :key",
            ['key' => $key]
        );
        $row = $result->fetchRow();

        return $row !== null
            ? (int) unserialize($row['value'], ['allowed_classes' => false])
            : $amount;
    }
}
