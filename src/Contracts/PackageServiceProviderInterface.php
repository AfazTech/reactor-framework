<?php
namespace Reactor\Contracts;

/**
 * Contract for service providers shipped by a Reactor package.
 *
 * Extends the base ServiceProviderInterface with a single additional
 * method that declares the package's publishable assets. Those assets
 * can be copied into the host application's directory tree by running
 * the `vendor:publish` console command.
 *
 * A provider implementing this interface is normally declared inside
 * the package's composer.json under `extra.reactor.providers`.
 */
interface PackageServiceProviderInterface extends ServiceProviderInterface
{
    /**
     * Get the assets that should be published into the host application.
     *
     * Each entry maps a source path (file or directory, absolute or
     * relative to the package) to a destination path inside the host
     * application. Destination paths are usually built with the helpers
     * config_path(), database_path(), storage_path(), lang_path(), ...
     *
     * Example:
     *
     *   return [
     *       __DIR__ . '/../config/my-package.php' => config_path('my-package.php'),
     *       __DIR__ . '/../database/migrations'   => database_path('migrations'),
     *   ];
     *
     * @return array<string, string> source => destination
     */
    public function publishes(): array;
}
