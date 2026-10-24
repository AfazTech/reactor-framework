<?php
namespace Reactor\Database\Migrations;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Config;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Core\Paths;
use Reactor\Core\Container;

/**
 * Facade over migration repository and runner with a file-based lock.
 *
 * The Migrator performs no database work in its constructor: resolving
 * it during application bootstrap (webhook, polling) has zero side
 * effects. The migrations tracking table is created lazily the first
 * time an actual migration operation runs, via ensureReady().
 *
 * The Migrator does not assume a fixed migration path; it consumes a
 * MigrationRegistrar that has already been populated by the application
 * and by any packages or extensions.
 *
 * A MigrationEventListenerInterface can be attached to render
 * per-migration progress from a command. The listener is forwarded to
 * the underlying MigrationRunner.
 */
class Migrator
{
    private MigrationRepository $repository;
    private MigrationRunner $runner;
    private MigrationLock $lock;
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger,
        Config $config,
        DatabaseManagerInterface $db,
        Paths $paths,
        Container $container,
        MigrationRegistrar $registrar
    ) {
        $this->logger = $logger;

        // Register the application's default migration source if the app
        // entry point did not do it already. add() is idempotent, so this
        // is safe when App has already registered the same path.
        $registrar->add(
            $paths->database() . '/migrations',
            (string) $config->get('database.migrations.namespace', 'App\\Migrations')
        );

        $this->repository = new MigrationRepository($db, $logger, $registrar, $container);
        $this->runner = new MigrationRunner($db, $logger, $this->repository);

        $appName = $config->get('app.name', 'reactor');
        $lockFile = $paths->storage() . '/locks/' . $appName . '_migration.lock';
        $this->lock = new MigrationLock($logger, $lockFile);
    }

    /**
     * Attach an optional listener that receives per-migration events.
     *
     * Passing null detaches the current listener. The listener is
     * forwarded to the underlying MigrationRunner.
     */
    public function setListener(?MigrationEventListenerInterface $listener): void
    {
        $this->runner->setListener($listener);
    }

    /**
     * Prepare migration infrastructure (create tracking table if needed).
     *
     * Idempotent and safe to call multiple times. Invoked automatically
     * at the start of every operation that touches the tracking table,
     * so callers never need to invoke it manually.
     */
    public function ensureReady(): void
    {
        $this->repository->ensureMigrationsTable();
    }

    public function getMigrations(): array { return $this->repository->getMigrations(); }
    public function getRanMigrations(): array { return $this->repository->getRanMigrations(); }
    public function getPendingMigrations(): array { return $this->repository->getPendingMigrations(); }

    public function status(): array
    {
        $this->ensureReady();
        return $this->repository->status();
    }

    /**
     * Return the batch numbers that a rollback($step) call would target.
     *
     * Used by the rollback command to decide whether there is anything
     * to roll back and to render the batch count without duplicating
     * the "getBatches + empty check" logic.
     *
     * @return array<int, int>
     */
    public function getBatchesForRollback(int $steps = 1): array
    {
        $this->ensureReady();
        return $this->repository->getBatches($steps);
    }

    public function run(): void
    {
        $this->ensureReady();
        $this->lock->acquire();
        try {
            $this->runner->run();
        } finally {
            $this->lock->release();
        }
    }

    public function rollback(int $steps = 1): void
    {
        $this->ensureReady();
        $this->lock->acquire();
        try {
            $this->runner->rollback($steps);
        } finally {
            $this->lock->release();
        }
    }

    public function reset(): void
    {
        $this->ensureReady();
        $this->lock->acquire();
        try {
            $this->runner->reset();
        } finally {
            $this->lock->release();
        }
    }

    public function refresh(): void
    {
        $this->ensureReady();
        $this->lock->acquire();
        try {
            $this->runner->refresh();
        } finally {
            $this->lock->release();
        }
    }
}
