<?php

namespace Reactor\Cache\Drivers;

use Amp\Future;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Reactor\Contracts\CacheInterface;

/**
 * Non-blocking PostgreSQL cache backed by amphp/postgres.
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

        $this->pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $this->table = $this->normalizeTableName($config['table'] ?? 'cache');
        $this->allowedClasses = $config['allowed_classes'] ?? false;
    }

    public function get(string $key, mixed $default = null): Future
    {
        $this->ensureTable();

        $result = $this->pool->execute(
            "SELECT \"value\", \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = :key",
            ['key' => $key]
        );

        $row = $result->fetchRow();

        if ($row === null) {
            return Future::complete($default);
        }

        $expiresAt = $row['expires_at'] !== null ? (int) $row['expires_at'] : null;
        if ($expiresAt !== null && time() > $expiresAt) {
            $this->delete($key)->await();
            return Future::complete($default);
        }

        return Future::complete(
            unserialize($row['value'], ['allowed_classes' => $this->allowedClasses])
        );
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        $this->ensureTable();

        $expiresAt = $ttl !== null && $ttl > 0 ? time() + $ttl : null;

        $this->pool->execute(
            "INSERT INTO \"{$this->table}\" (\"key\", \"value\", \"expires_at\")
             VALUES (:key, :value, :expires_at)
             ON CONFLICT (\"key\") DO UPDATE
             SET \"value\" = EXCLUDED.\"value\", \"expires_at\" = EXCLUDED.\"expires_at\"",
            [
                'key'        => $key,
                'value'      => serialize($value),
                'expires_at' => $expiresAt,
            ]
        );

        return Future::complete(true);
    }

    public function delete(string $key): Future
    {
        $this->ensureTable();

        $this->pool->execute(
            "DELETE FROM \"{$this->table}\" WHERE \"key\" = :key",
            ['key' => $key]
        );

        return Future::complete(true);
    }

    public function has(string $key): Future
    {
        $this->ensureTable();

        $result = $this->pool->execute(
            "SELECT \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = :key",
            ['key' => $key]
        );

        $row = $result->fetchRow();

        if ($row === null) {
            return Future::complete(false);
        }

        $expiresAt = $row['expires_at'] !== null ? (int) $row['expires_at'] : null;
        if ($expiresAt !== null && time() > $expiresAt) {
            $this->delete($key)->await();
            return Future::complete(false);
        }

        return Future::complete(true);
    }

    public function clear(): Future
    {
        $this->ensureTable();
        $this->pool->execute("DELETE FROM \"{$this->table}\"");
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
        $this->ensureTable();

        $new = $this->pool->beginTransaction(function (PostgresConnectionPool $pool) use ($key, $amount) {
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

        return Future::complete($new);
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

    private function normalizeTableName(string $table): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException(
                'Invalid cache table name; only letters, digits and underscores are allowed.'
            );
        }

        return $table;
    }
}
