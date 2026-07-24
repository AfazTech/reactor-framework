<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Database\Migrations\MigrationRunner;
use Reactor\Database\Migrations\MigrationRepository;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Database\Migrations\Migration;
use PHPUnit\Framework\MockObject\MockObject;

class MigrationRunnerTest extends TestCase
{
    private DatabaseManagerInterface&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private MigrationRepository&MockObject $repository;
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repository = $this->createMock(MigrationRepository::class);
        $this->runner = new MigrationRunner($this->db, $this->logger, $this->repository);
    }

    /** @test */
    public function it_runs_pending_migrations()
    {
        $pending = [
            '2026_07_16_000002_create_users_table' => '/path/migration1.php',
            '2026_07_22_000000_create_jobs_table' => '/path/migration2.php',
        ];

        $this->repository
            ->method('getPendingMigrations')
            ->willReturn($pending);

        $this->repository
            ->method('getNextBatchNumber')
            ->willReturn(2);

        $migration1 = $this->createMock(Migration::class);
        $migration1->expects($this->once())->method('up');

        $migration2 = $this->createMock(Migration::class);
        $migration2->expects($this->once())->method('up');

        $this->repository
            ->method('loadMigration')
            ->willReturnMap([
                ['2026_07_16_000002_create_users_table', $migration1],
                ['2026_07_22_000000_create_jobs_table', $migration2],
            ]);

        $this->db
            ->expects($this->exactly(2))
            ->method('transaction')
            ->willReturnCallback(function ($callback) {
                $callback();
            });

        $this->repository
            ->expects($this->exactly(2))
            ->method('markRan')
            ->withConsecutive(
                ['2026_07_16_000002_create_users_table', 2],
                ['2026_07_22_000000_create_jobs_table', 2]
            );

        // Expect two individual migration info logs + one completion log
        $this->logger
            ->expects($this->exactly(3))
            ->method('info')
            ->withConsecutive(
                ['Migration executed: 2026_07_16_000002_create_users_table'],
                ['Migration executed: 2026_07_22_000000_create_jobs_table'],
                ['Migrations completed: 2 executed']
            );

        $this->runner->run();
    }

    /** @test */
    public function it_does_nothing_when_no_pending_migrations()
    {
        $this->repository
            ->method('getPendingMigrations')
            ->willReturn([]);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('No pending migrations');

        $this->db->expects($this->never())->method('transaction');

        $this->runner->run();
    }

    /** @test */
    public function it_rolls_back_last_batch()
    {
        $this->repository
            ->method('getBatches')
            ->with(1)
            ->willReturn([3]);

        $migrationRows = [
            (object) ['migration' => '2026_07_16_000002_create_users_table'],
            (object) ['migration' => '2026_07_22_000000_create_jobs_table'],
        ];

        $this->repository
            ->method('getMigrationsByBatch')
            ->with(3)
            ->willReturn($migrationRows);

        $migration1 = $this->createMock(Migration::class);
        $migration1->expects($this->once())->method('down');

        $migration2 = $this->createMock(Migration::class);
        $migration2->expects($this->once())->method('down');

        $this->repository
            ->method('loadMigration')
            ->willReturnMap([
                ['2026_07_16_000002_create_users_table', $migration1],
                ['2026_07_22_000000_create_jobs_table', $migration2],
            ]);

        $this->db
            ->expects($this->exactly(2))
            ->method('transaction')
            ->willReturnCallback(function ($callback) {
                $callback();
            });

        $this->repository
            ->expects($this->exactly(2))
            ->method('remove')
            ->withConsecutive(
                ['2026_07_16_000002_create_users_table'],
                ['2026_07_22_000000_create_jobs_table']
            );

        $this->logger
            ->expects($this->exactly(3))
            ->method('info')
            ->withConsecutive(
                ['Rolled back: 2026_07_16_000002_create_users_table'],
                ['Rolled back: 2026_07_22_000000_create_jobs_table'],
                ['Rolled back batch: 3']
            );

        $this->runner->rollback(1);
    }

    /** @test */
    public function it_does_nothing_when_nothing_to_rollback()
    {
        $this->repository
            ->method('getBatches')
            ->with(1)
            ->willReturn([]);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Nothing to rollback');

        $this->db->expects($this->never())->method('transaction');

        $this->runner->rollback(1);
    }

    /** @test */
    public function it_resets_all_migrations()
    {
        $this->repository
            ->method('getBatches')
            ->with(PHP_INT_MAX)
            ->willReturn([2, 1]);

        $this->repository
            ->method('getMigrationsByBatch')
            ->willReturnMap([
                [2, [(object) ['migration' => 'migration_a']]],
                [1, [(object) ['migration' => 'migration_b']]],
            ]);

        $migrationMock = $this->createMock(Migration::class);
        $migrationMock->method('down');

        $this->repository
            ->method('loadMigration')
            ->willReturn($migrationMock);

        $this->db
            ->method('transaction')
            ->willReturnCallback(function ($callback) {
                $callback();
            });

        $this->repository
            ->expects($this->exactly(2))
            ->method('remove')
            ->withConsecutive(
                ['migration_a'],
                ['migration_b']
            );

        // Reset calls rollbackBatch for each batch
        // For batch 2: logs "Rolled back: migration_a" then "Rolled back batch: 2"
        // For batch 1: logs "Rolled back: migration_b" then "Rolled back batch: 1"
        $this->logger
            ->expects($this->exactly(4))
            ->method('info')
            ->withConsecutive(
                ['Rolled back: migration_a'],
                ['Rolled back batch: 2'],
                ['Rolled back: migration_b'],
                ['Rolled back batch: 1']
            );

        $this->runner->reset();
    }

    /** @test */
    public function it_resets_and_runs_on_refresh_when_migrations_exist()
    {
        // Mock reset first
        $this->repository
            ->method('getBatches')
            ->with(PHP_INT_MAX)
            ->willReturn([1]);

        $this->repository
            ->method('getMigrationsByBatch')
            ->with(1)
            ->willReturn([(object) ['migration' => 'test_migration']]);

        $migrationMock = $this->createMock(Migration::class);
        $migrationMock->method('down');
        $migrationMock->method('up');

        $this->repository
            ->method('loadMigration')
            ->willReturn($migrationMock);

        $this->db
            ->method('transaction')
            ->willReturnCallback(function ($callback) {
                $callback();
            });

        // Then run
        $this->repository
            ->method('getPendingMigrations')
            ->willReturn([]);

        // Refresh: reset (2 logs: rollback + batch) + run (1 log: no pending)
        $this->logger
            ->expects($this->exactly(3))
            ->method('info')
            ->withConsecutive(
                ['Rolled back: test_migration'],
                ['Rolled back batch: 1'],
                ['No pending migrations']
            );

        $this->runner->refresh();
    }

    /** @test */
    public function it_refreshes_by_resetting_and_running_when_pending_exist()
    {
        // Mock reset
        $this->repository
            ->method('getBatches')
            ->with(PHP_INT_MAX)
            ->willReturn([1]);

        $this->repository
            ->method('getMigrationsByBatch')
            ->with(1)
            ->willReturn([(object) ['migration' => 'test_migration']]);

        $migrationMock = $this->createMock(Migration::class);
        $migrationMock->method('down');
        $migrationMock->method('up');

        $this->repository
            ->method('loadMigration')
            ->willReturn($migrationMock);

        $this->db
            ->method('transaction')
            ->willReturnCallback(function ($callback) {
                $callback();
            });

        // Then run
        $pending = ['2026_07_16_000002_new_migration' => '/path/new.php'];
        $this->repository
            ->method('getPendingMigrations')
            ->willReturn($pending);

        $this->repository
            ->method('getNextBatchNumber')
            ->willReturn(2);

        $this->repository
            ->method('markRan');

        // Refresh: reset (2 logs) + run (2 logs: migration executed + completion)
        $this->logger
            ->expects($this->exactly(4))
            ->method('info')
            ->withConsecutive(
                ['Rolled back: test_migration'],
                ['Rolled back batch: 1'],
                ['Migration executed: 2026_07_16_000002_new_migration'],
                ['Migrations completed: 1 executed']
            );

        $this->runner->refresh();
    }

    /** @test */
    public function it_does_nothing_on_reset_when_no_batches()
    {
        $this->repository
            ->method('getBatches')
            ->with(PHP_INT_MAX)
            ->willReturn([]);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Nothing to reset');

        $this->db->expects($this->never())->method('transaction');

        $this->runner->reset();
    }
}
