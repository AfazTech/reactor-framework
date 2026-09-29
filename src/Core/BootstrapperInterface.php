<?php
namespace Reactor\Core;

/**
 * Interface for application bootstrappers.
 *
 * A bootstrapper performs a specific set of initialisation tasks
 * (e.g., registering services, configuring the router).
 */
interface BootstrapperInterface
{
    /**
     * Execute the bootstrap process.
     *
     * @param Container $container The DI container.
     */
    public function bootstrap(Container $container): void;
}
