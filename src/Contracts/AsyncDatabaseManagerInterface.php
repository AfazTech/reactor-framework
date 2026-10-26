<?php

namespace Reactor\Contracts;

use Amp\Future;

/**
 * Asynchronous database contract for AMPHP-backed drivers.
 *
 * This interface is intentionally separate from DatabaseManagerInterface
 * so that synchronous Eloquent usage and asynchronous drivers can
 * coexist in the same application. Consumers that want non-blocking
 * database access should type-hint this interface.
 *
 * All methods return an Amp\Future and must be awaited inside a fiber
 * or inside an event-loop callback.
 */
interface AsyncDatabaseManagerInterface
{
    /**
     * Return the driver name (mysql, pgsql, sqlite).
     */
    public function getDriver(): string;

    /**
     * Execute a SELECT and return every row.
     *
     * @param array<string|int, mixed> $params
     * @return Future<array<int, array<string, mixed>>>
     */
    public function fetchAll(string $sql, array $params = []): Future;

    /**
     * Execute a SELECT and return the first row, or null.
     *
     * @param array<string|int, mixed> $params
     * @return Future<?array<string, mixed>>
     */
    public function fetchOne(string $sql, array $params = []): Future;

    /**
     * Execute an INSERT, UPDATE or DELETE and return the affected row count.
     *
     * @param array<string|int, mixed> $params
     * @return Future<int>
     */
    public function execute(string $sql, array $params = []): Future;

    /**
     * Run the callback inside a transaction.
     *
     * The callback receives an AsyncDatabaseManagerInterface bound to
     * the active transaction, so nested calls participate in it. The
     * returned future resolves to the callback's return value, or fails
     * after rolling back when the callback throws.
     *
     * @return Future<mixed>
     */
    public function transaction(callable $callback): Future;
}
