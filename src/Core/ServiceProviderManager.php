<?php
namespace Reactor\Core;

use Reactor\Contracts\ServiceProviderInterface;

/**
 * Manages service providers: registration and booting.
 */
class ServiceProviderManager
{
    private Container $container;
    private array $providers = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function add(ServiceProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    public function registerAll(): void
    {
        foreach ($this->providers as $provider) {
            $provider->register($this->container);
        }
    }

    public function bootAll(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }
    }
}
