<?php
namespace Reactor\Database\Seeders;

/**
 * Abstract base class for database seeders.
 */
abstract class Seeder
{
    abstract public function run(): void;

    /**
     * Call another seeder from within a seeder.
     *
     * @param string $seederClass
     */
    protected function call(string $seederClass): void
    {
        $seeder = new $seederClass();
        if (!($seeder instanceof Seeder)) {
            throw new \RuntimeException("Seeder must extend Reactor\\Database\\Seeders\\Seeder: $seederClass");
        }
        $seeder->run();
    }
}
