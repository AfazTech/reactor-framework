<?php
namespace Reactor\Database\Seeders;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;

/**
 * Executes a seeder class.
 */
class SeederRunner
{
    private DatabaseManagerInterface $db;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger, DatabaseManagerInterface $db)
    {
        $this->logger = $logger;
        $this->db = $db;
    }

    public function run(string $class = 'Reactor\\Seeders\\DatabaseSeeder'): void
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Seeder class not found: $class");
        }
        $seeder = new $class();
        if (!($seeder instanceof Seeder)) {
            throw new \RuntimeException("Seeder must extend Reactor\\Database\\Seeders\\Seeder: $class");
        }
        $seeder->run();
        $this->logger->info("Seeder executed: $class");
    }
}
