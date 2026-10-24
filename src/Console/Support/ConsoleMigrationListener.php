<?php
namespace Reactor\Console\Support;

use Reactor\Database\Migrations\MigrationEventListenerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders migration lifecycle events to a Symfony Console output.
 *
 * Output mirrors Laravel's migrate command so that operators familiar
 * with that workflow can read progress at a glance:
 *
 *   Migrating: 2026_07_16_000002_create_users_table
 *   Migrated:  2026_07_16_000002_create_users_table (25.14ms)
 */
final class ConsoleMigrationListener implements MigrationEventListenerInterface
{
    public function __construct(private OutputInterface $output)
    {
    }

    public function onMigrating(string $migration, int $batch): void
    {
        $this->output->writeln("Migrating: {$migration}");
    }

    public function onMigrated(string $migration, int $batch, float $elapsedMs): void
    {
        $this->output->writeln(sprintf('Migrated:  %s (%.2fms)', $migration, $elapsedMs));
    }

    public function onRollingBack(string $migration, int $batch): void
    {
        $this->output->writeln("Rolling back: {$migration}");
    }

    public function onRolledBack(string $migration, int $batch, float $elapsedMs): void
    {
        $this->output->writeln(sprintf('Rolled back:  %s (%.2fms)', $migration, $elapsedMs));
    }
}
