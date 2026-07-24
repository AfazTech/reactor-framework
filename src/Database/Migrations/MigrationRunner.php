<?php
namespace Reactor\Database\Migrations;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;

/**
 * Executes migrations (run, rollback, reset, refresh).
 */
class MigrationRunner
{
    private DatabaseManagerInterface $db;
    private LoggerInterface $logger;
    private MigrationRepository $repository;

    public function __construct(DatabaseManagerInterface $db, LoggerInterface $logger, MigrationRepository $repository)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->repository = $repository;
    }

    public function run(): void
    {
        $pending = $this->repository->getPendingMigrations();
        if (empty($pending)) {
            $this->logger->info('No pending migrations');
            return;
        }

        $batch = $this->repository->getNextBatchNumber();
        foreach ($pending as $name => $file) {
            $this->runMigration($name, $batch);
        }
        $this->logger->info('Migrations completed: ' . count($pending) . ' executed');
    }

    private function runMigration(string $name, int $batch): void
    {
        $instance = $this->repository->loadMigration($name);

        $this->db->transaction(function () use ($instance, $name, $batch) {
            $instance->up();
            $this->repository->markRan($name, $batch);
        });

        $this->logger->info("Migration executed: $name");
    }

    public function rollback(int $steps = 1): void
    {
        $batches = $this->repository->getBatches($steps);
        if (empty($batches)) {
            $this->logger->info('Nothing to rollback');
            return;
        }

        foreach ($batches as $batch) {
            $this->rollbackBatch((int) $batch);
        }
    }

    private function rollbackBatch(int $batch): void
    {
        $migrations = $this->repository->getMigrationsByBatch($batch);
        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration->migration);
            $this->repository->remove($migration->migration);
        }
        $this->logger->info("Rolled back batch: $batch");
    }

    private function rollbackMigration(string $name): void
    {
        $instance = $this->repository->loadMigration($name);

        $this->db->transaction(function () use ($instance) {
            $instance->down();
        });

        $this->logger->info("Rolled back: $name");
    }

    public function reset(): void
    {
        $batches = $this->repository->getBatches(PHP_INT_MAX);
        if (empty($batches)) {
            $this->logger->info('Nothing to reset');
            return;
        }

        foreach ($batches as $batch) {
            $this->rollbackBatch((int) $batch);
        }
    }

    public function refresh(): void
    {
        $this->reset();
        $this->run();
    }
}
