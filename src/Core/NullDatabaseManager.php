<?php

namespace Reactor\Core;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;

/**
 * Null implementation of DatabaseManagerInterface.
 *
 * Used as a fallback binding when the host application has not
 * registered a real database manager. Every schema/query method
 * throws a descriptive RuntimeException so that misuse (e.g. running
 * migrations without configuring a database) fails loudly rather
 * than silently returning empty results.
 *
 * transaction() is the only exception: it simply executes the given
 * callback, since it does not itself need a live connection.
 * getConfig() returns an empty array so callers that only inspect
 * driver metadata can degrade gracefully.
 */
class NullDatabaseManager implements DatabaseManagerInterface
{
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function connection(): object
    {
        $this->fail('connection');
    }

    /**
     * {@inheritdoc}
     */
    public function schema(): object
    {
        $this->fail('schema');
    }

    /**
     * {@inheritdoc}
     */
    public function table(string $table): object
    {
        $this->fail("table('{$table}')");
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): void
    {
        $this->fail('beginTransaction');
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): void
    {
        $this->fail('commit');
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack(): void
    {
        $this->fail('rollBack');
    }

    /**
     * {@inheritdoc}
     *
     * Executes the callback directly without a live connection.
     */
    public function transaction(callable $callback)
    {
        return $callback();
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(): array
    {
        return [];
    }

    /**
     * Log a helpful warning and throw a descriptive exception.
     *
     * @throws \RuntimeException Always.
     */
    private function fail(string $method): never
    {
        $this->logger?->warning(
            "NullDatabaseManager::{$method}() called but no database is configured.",
            ['hint' => 'Bind DatabaseManagerInterface in a service provider.']
        );

        throw new \RuntimeException(
            "No database configured. Cannot call DatabaseManagerInterface::{$method}(). "
            . 'Register a DatabaseManagerInterface binding (for example '
            . 'App\\Database\\EloquentManager) in a service provider before '
            . 'using database features.'
        );
    }
}
