<?php

namespace Reactor\Database\Async;

use Amp\Future;
use Reactor\Contracts\AsyncDatabaseManagerInterface;

/**
 * Adapter that implements AsyncDatabaseManagerInterface from callables.
 *
 * Used internally to expose a transaction object through the same
 * interface as the parent manager, so callbacks passed to transaction()
 * can keep using fetchAll() / fetchOne() / execute() without knowing
 * whether they are running inside a transaction.
 */
final class CallbackAsyncDatabaseManager implements AsyncDatabaseManagerInterface
{
    /**
     * @param \Closure(string, array): Future $fetchAll
     * @param \Closure(string, array): Future $fetchOne
     * @param \Closure(string, array): Future $execute
     * @param \Closure(callable): Future     $transaction
     */
    public function __construct(
        private readonly string $driver,
        private readonly \Closure $fetchAll,
        private readonly \Closure $fetchOne,
        private readonly \Closure $execute,
        private readonly \Closure $transaction,
    ) {
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function fetchAll(string $sql, array $params = []): Future
    {
        return ($this->fetchAll)($sql, $params);
    }

    public function fetchOne(string $sql, array $params = []): Future
    {
        return ($this->fetchOne)($sql, $params);
    }

    public function execute(string $sql, array $params = []): Future
    {
        return ($this->execute)($sql, $params);
    }

    public function transaction(callable $callback): Future
    {
        return ($this->transaction)($callback);
    }
}
