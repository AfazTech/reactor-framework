<?php

namespace Reactor\Database\Migrations;

use Reactor\Contracts\DatabaseManagerInterface;

/**
 * Abstract base class for database migrations.
 *
 * Now uses DatabaseManagerInterface instead of direct Eloquent dependency.
 */
abstract class Migration
{
    protected DatabaseManagerInterface $db;

    public function __construct(DatabaseManagerInterface $db)
    {
        $this->db = $db;
    }

    abstract public function up(): void;

    abstract public function down(): void;
}
