<?php

namespace Reactor\Cache\Drivers;

use Amp\Future;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteConnection;
use Reactor\Contracts\CacheInterface;

/**
 * Non-blocking SQLite cache backed by fabpot/amp-sqlite3.
 */
class SqliteCache implements CacheInterface
{
    private SqliteConnection $connection;
    private string $table;
    private bool|array $allowedClasses;
    private bool $tableEnsured = false;

    public function __construct(array $config)
    {
        $path = (string) ($config['database'] ?? ':memory:');

        $sqliteConfig = (new SqliteConfig($path))
            ->withBusyTimeout(5_000)
            ->withForeignKeys(true);

        $this->connection = (new SqliteConnector())->connect($sqliteConfig);
        $this->table = $this->normalizeTableName($config['table'] ?? 'cache');
        $this->allowedClasses = $config['allowed_classes'] ?? false;
    }

    public function __destruct()
    {
        $this->connection->close();
    }

    public function get(string $key, mixed $default = null): Future
    {
        $this->ensureTable();

        $result = $this->connection->execute(
            "SELECT \"value\", \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = ?",
            [$key]
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

        $this->connection->execute(
            "INSERT INTO \"{$this->table}\" (\"key\", \"value\", \"expires_at\")
             VALUES (?, ?, ?)
             ON CONFLICT(\"key\") DO UPDATE
             SET \"value\" = excluded.\"value\", \"expires_at\" = excluded.\"expires_at\"",
            [$key, serialize($value), $expiresAt]
        );

        return Future::complete(true);
    }

    public function delete(string $key): Future
    {
        $this->ensureTable();

        $this->connection->execute(
            "DELETE FROM \"{$this->table}\" WHERE \"key\" = ?",
            [$key]
        );

        return Future::complete(true);
    }

    public function has(string $key): Future
    {
        $this->ensureTable();

        $result = $this->connection->execute(
            "SELECT \"expires_at\" FROM \"{$this->table}\" WHERE \"key\" = ?",
            [$key]
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
        $this->connection->execute("DELETE FROM \"{$this->table}\"");
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

            return Future::complete($new);
        } catch (\Throwable $e) {
            $transaction->rollback();
            throw $e;
        }
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
