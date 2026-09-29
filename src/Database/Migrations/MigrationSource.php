<?php
namespace Reactor\Database\Migrations;

/**
 * Value object describing a single migration source.
 *
 * A source is a directory that contains migration files together with
 * the PHP namespace those migration classes declare. The application and
 * any package or extension can register one or more sources with the
 * MigrationRegistrar, which lets the migration system discover and load
 * migrations from arbitrary locations instead of a single hard-coded
 * path.
 */
final class MigrationSource
{
    /**
     * Absolute path to the directory containing migration files.
     */
    public readonly string $path;

    /**
     * Fully-qualified PHP namespace declared by migration classes
     * inside this directory (no trailing backslash).
     */
    public readonly string $namespace;

    public function __construct(string $path, string $namespace)
    {
        $this->path = rtrim($path, '/');
        $this->namespace = trim($namespace, '\\');
    }
}
