<?php

namespace Reactor\Cache\Drivers;

use Amp\Future;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Reactor\Contracts\CacheInterface;

/**
 * Non-blocking SQLite cache backed by fabpot/amp-sqlite3.
 *
 * The cache table is created on first use:
 *   CREATE TABLE IF NOT EXISTS cache (
 *       key TEXT PRIMARY KEY,
 *       value TEXT NOT NULL,
 *       expires_at INTEGER NULL
 *   )
 *
 * Requires PHP 8.4+ and ext-sqlite3.
 */
class SqliteCache implements CacheInterface
{
    private \Fabpot\Amp\Sqlite\SqliteConnection $connection;
    private string $table;
    private bool|array $allowedClasses;
    private bool $tableEnsured = false;

    public function __construct(array $config)
    {
        $path = $config['database'] ?? ':memory:';

        $sqliteConfig = (new SqliteConfig($path))
            ->withBusyTimeout(5_000)
            ->withForeignKeys(true);

        $this->connection = (new SqliteConnector())->connect($sqliteConfig);
        $this->table = $config['table'] ?? 'cache';
        $this->allowedClasses = $config['allowed_classes'] ?? true;
    }

    public function __destruct()
    {
        $this->connection->close();
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
        $this->connection->execute("DELETE FROM \"{$this->table}\"");
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

        $this->connection->execute(
            "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (
                \"key\" TEXT PRIMARY KEY,
                \"value\" TEXT NOT NULL,
                \"expires_at\" INTEGER NULL
            )"
        );

        $this->tableEnsured = true;
    }

    private function doGet(string $key, mixed $default = null): mixed
    {
        $this->ensureTable();

        $result = $this->connection->query(
            "SELECT \"value\", \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = ?",
            [$key]
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

        $this->connection->execute(
            "INSERT INTO \"{$this->table}\" (\"key\", \"value\", \"expires_at\")
             VALUES (?, ?, ?)
             ON CONFLICT(\"key\") DO UPDATE
             SET \"value\" = excluded.\"value\", \"expires_at\" = excluded.\"expires_at\"",
            [$key, serialize($value), $expiresAt]
        );

        return true;
    }

    private function doDelete(string $key): bool
    {
        $this->ensureTable();
        $this->connection->execute(
            "DELETE FROM \"{$this->table}\" WHERE \"key\" = ?",
            [$key]
        );
        return true;
    }

    private function doHas(string $key): bool
    {
        $this->ensureTable();

        $result = $this->connection->query(
            "SELECT \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = ?",
            [$key]
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

        $transaction = $this->connection->beginTransaction();

        try {
            $result = $transaction->execute(
                "SELECT \"value\", \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = ?",
                [$key]
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

            $transaction->execute(
                "INSERT INTO \"{$this->table}\" (\"key\", \"value\", \"expires_at\")
                 VALUES (?, ?, ?)
                 ON CONFLICT(\"key\") DO UPDATE
                 SET \"value\" = excluded.\"value\", \"expires_at\" = excluded.\"expires_at\"",
                [$key, serialize($new), $expiresAt]
            );

            $transaction->commit();

            return $new;
        } catch (\Throwable $e) {
            $transaction->rollback();
            throw $e;
        }
    }
}
