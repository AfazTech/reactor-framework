<?php

namespace Reactor\Database\Async;

use Amp\Future;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnection;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use function Amp\async;

/**
 * Non-blocking SQLite driver backed by fabpot/amp-sqlite3.
 *
 * Requires PHP 8.4+, ext-sqlite3 and SQLite 3.31.0+.
 */
final class AsyncSqliteManager implements AsyncDatabaseManagerInterface
{
    private SqliteConnection $connection;

    public function __construct(array $config)
    {
        $path = (string) ($config['database'] ?? ':memory:');

        $sqliteConfig = (new SqliteConfig($path))
            ->withBusyTimeout(5_000)
            ->withForeignKeys(true);

        $this->connection = (new SqliteConnector())->connect($sqliteConfig);
    }

    public function __destruct()
    {
        $this->connection->close();
    }

    public function getDriver(): string
    {
        return 'sqlite';
    }

    public function fetchAll(string $sql, array $params = []): Future
    {
        return async(function () use ($sql, $params): array {
            $rows = [];
            foreach ($this->connection->query($sql, $params) as $row) {
                $rows[] = $row;
            }
            return $rows;
        });
    }

    public function fetchOne(string $sql, array $params = []): Future
    {
        return async(fn(): ?array => $this->connection->query($sql, $params)->fetchRow());
    }

    public function execute(string $sql, array $params = []): Future
    {
        return async(fn(): int => $this->connection->execute($sql, $params)->getRowCount());
    }

    public function transaction(callable $callback): Future
    {
        return async(function () use ($callback) {
            $transaction = $this->connection->beginTransaction();

            try {
                $result = $callback($this->wrap($transaction));

                if ($result instanceof Future) {
                    $result = $result->await();
                }

                $transaction->commit();

                return $result;
            } catch (\Throwable $e) {
                $transaction->rollback();
                throw $e;
            }
        });
    }

    private function wrap(SqliteTransaction $transaction): AsyncDatabaseManagerInterface
    {
        return new CallbackAsyncDatabaseManager(
            driver: 'sqlite',
            fetchAll: function (string $sql, array $params) use ($transaction): Future {
                return async(function () use ($transaction, $sql, $params): array {
                    $rows = [];
                    foreach ($transaction->query($sql, $params) as $row) {
                        $rows[] = $row;
                    }
                    return $rows;
                });
            },
            fetchOne: fn(string $sql, array $params): Future => async(
                fn(): ?array => $transaction->query($sql, $params)->fetchRow()
            ),
            execute: fn(string $sql, array $params): Future => async(
                fn(): int => $transaction->execute($sql, $params)->getRowCount()
            ),
            transaction: fn(callable $cb): Future => $this->transaction($cb),
        );
    }
}
