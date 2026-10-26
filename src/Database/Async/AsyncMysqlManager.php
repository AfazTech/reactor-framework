<?php

namespace Reactor\Database\Async;

use Amp\Future;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlTransaction;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use function Amp\async;

/**
 * Non-blocking MySQL / MariaDB driver backed by amphp/mysql.
 */
final class AsyncMysqlManager implements AsyncDatabaseManagerInterface
{
    private MysqlConnectionPool $pool;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'host=%s port=%d user=%s password=%s db=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            $config['database'] ?? 'reactor',
        );

        $this->pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
    }

    public function getDriver(): string
    {
        return 'mysql';
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

    private function wrap(MysqlTransaction $transaction): AsyncDatabaseManagerInterface
    {
        return new CallbackAsyncDatabaseManager(
            driver: 'mysql',
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
