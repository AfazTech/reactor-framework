<?php

namespace Reactor\Database\Async;

use Amp\Future;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresTransaction;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use function Amp\async;

/**
 * Non-blocking PostgreSQL driver backed by amphp/postgres.
 *
 * Requires ext-pgsql or pecl-pq on the target environment.
 */
final class AsyncPostgresManager implements AsyncDatabaseManagerInterface
{
    private PostgresConnectionPool $pool;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'host=%s port=%d user=%s password=%s dbname=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 5432,
            $config['username'] ?? 'postgres',
            $config['password'] ?? '',
            $config['database'] ?? 'reactor',
        );

        $this->pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
    }

    public function getDriver(): string
    {
        return 'pgsql';
    }

    public function fetchAll(string $sql, array $params = []): Future
    {
        return async(function () use ($sql, $params): array {
            $rows = [];
            foreach ($this->pool->execute($sql, $params) as $row) {
                $rows[] = $row;
            }
            return $rows;
        });
    }

    public function fetchOne(string $sql, array $params = []): Future
    {
        return async(fn(): ?array => $this->pool->execute($sql, $params)->fetchRow());
    }

    public function execute(string $sql, array $params = []): Future
    {
        return async(fn(): int => $this->pool->execute($sql, $params)->getRowCount());
    }

    public function transaction(callable $callback): Future
    {
        return async(function () use ($callback) {
            $transaction = $this->pool->beginTransaction();

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

    private function wrap(PostgresTransaction $transaction): AsyncDatabaseManagerInterface
    {
        return new CallbackAsyncDatabaseManager(
            driver: 'pgsql',
            fetchAll: function (string $sql, array $params) use ($transaction): Future {
                return async(function () use ($transaction, $sql, $params): array {
                    $rows = [];
                    foreach ($transaction->execute($sql, $params) as $row) {
                        $rows[] = $row;
                    }
                    return $rows;
                });
            },
            fetchOne: fn(string $sql, array $params): Future => async(
                fn(): ?array => $transaction->execute($sql, $params)->fetchRow()
            ),
            execute: fn(string $sql, array $params): Future => async(
                fn(): int => $transaction->execute($sql, $params)->getRowCount()
            ),
            transaction: fn(callable $cb): Future => $this->transaction($cb),
        );
    }
}
