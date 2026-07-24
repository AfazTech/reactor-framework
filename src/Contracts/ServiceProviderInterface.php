<?php
namespace Reactor\Contracts;

use Reactor\Core\Container;

/**
 * Contract for service providers.
 *
 * Service providers are responsible for registering and booting services
 * in the dependency injection container.
 */
interface ServiceProviderInterface
{
    /**
     * Register services in the container.
     *
     * @param Container $container
     */
    public function register(Container $container): void;

    /**
     * Boot services after all providers are registered.
     *
     * @param Container $container
     */
    public function boot(Container $container): void;
}
