<?php
namespace Reactor\Database\Migrations;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Config;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Core\Paths;
use Reactor\Core\Container;

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
        Container $container
    ) {
        $this->logger = $logger;
        $migrationPath = $paths->database() . '/migrations';
        $this->repository = new MigrationRepository($db, $logger, $migrationPath, $container);
        $this->runner = new MigrationRunner($db, $logger, $this->repository);

        $appName = $config->get('app.name', 'reactor');
        $lockFile = $paths->storage() . '/locks/' . $appName . '_migration.lock';
        $this->lock = new MigrationLock($logger, $lockFile);
    }

    public function getMigrations(): array { return $this->repository->getMigrations(); }
    public function getRanMigrations(): array { return $this->repository->getRanMigrations(); }
    public function getPendingMigrations(): array { return $this->repository->getPendingMigrations(); }
    public function status(): array { return $this->repository->status(); }

    public function run(): void
    {
        $this->lock->acquire();
        try {
            $this->runner->run();
        } finally {
            $this->lock->release();
        }
    }

    public function rollback(int $steps = 1): void
    {
        $this->lock->acquire();
        try {
            $this->runner->rollback($steps);
        } finally {
            $this->lock->release();
        }
    }

    public function reset(): void
    {
        $this->lock->acquire();
        try {
            $this->runner->reset();
        } finally {
            $this->lock->release();
        }
    }

    public function refresh(): void
    {
        $this->lock->acquire();
        try {
            $this->runner->refresh();
        } finally {
            $this->lock->release();
        }
    }
}
