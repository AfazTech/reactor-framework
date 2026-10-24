<?php
namespace Reactor\Database\Migrations;

/**
 * Observer contract for migration lifecycle events.
 *
 * Commands attach an implementation of this interface to a Migrator so
 * that per-migration progress can be rendered to the console. The
 * runner remains usable without a listener; in that case only the
 * framework logger records what happened.
 */
interface MigrationEventListenerInterface
{
    public function onMigrating(string $migration, int $batch): void;

    public function onMigrated(string $migration, int $batch, float $elapsedMs): void;

    public function onRollingBack(string $migration, int $batch): void;

    public function onRolledBack(string $migration, int $batch, float $elapsedMs): void;
}
