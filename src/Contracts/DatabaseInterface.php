<?php
namespace Reactor\Contracts;

use Illuminate\Database\Capsule\Manager;

/**
 * Contract for database service providers.
 *
 * Defines the minimum requirements for a database connection manager.
 */
interface DatabaseInterface
{
    /**
     * Get the underlying database manager instance.
     *
     * @return Manager
     */
    public function getDb(): Manager;

    /**
     * Ensure that all owners are administrators.
     */
    public function ensureOwnersAreAdmins(): void;

    /**
     * Initialize default configurations.
     */
    public function initializeDefaultConfigs(): void;
}
