<?php
namespace Reactor\Contracts;

/**
 * Contract for a database manager that provides schema, query builder,
 * and transaction capabilities.
 */
interface DatabaseManagerInterface
{
    /**
     * Get the underlying database connection object.
     *
     * @return object
     */
    public function connection(): object;

    /**
     * Get the schema builder.
     *
     * @return object
     */
    public function schema(): object;

    /**
     * Get a query builder for the given table.
     *
     * @param string $table Table name.
     * @return object
     */
    public function table(string $table): object;

    /**
     * Begin a new transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the active transaction.
     */
    public function commit(): void;

    /**
     * Roll back the active transaction.
     */
    public function rollBack(): void;

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback);

    /**
     * Get the database configuration.
     *
     * @return array
     */
    public function getConfig(): array;
}
