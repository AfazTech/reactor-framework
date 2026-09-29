<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Core\BootstrapperInterface;
use Reactor\Core\Container;
use Reactor\Core\Providers\CoreServiceProvider;
use Reactor\Core\Providers\ScheduleServiceProvider;
use Reactor\Core\ServiceProviderManager;
use Reactor\Contracts\ServiceProviderInterface;

/**
 * Orchestrates the application bootstrap process.
 *
 * Three phases run in order:
 *
 *   1. Service providers are registered (register phase). Core and
 *      schedule providers run first, then any user-supplied providers.
 *   2. A caller-supplied list of BootstrapperInterface instances runs
 *      in order. Each bootstrapper initialises one slice of the
 *      framework (database, Telegram client, middleware discovery,
 *      routing, or any custom concern).
 *   3. Service providers are booted (boot phase).
 *
 * Because the bootstrapper list is supplied by the caller, a custom
 * skeleton can skip or replace any phase. For example, omitting
 * TelegramBootstrapper runs Reactor without a framework-managed
 * Telegram client, and inserting a custom bootstrapper at the front
 * lets the host application register its own Neili\Client binding
 * before the rest of the chain runs.
 */
class AppBootstrapper implements BootstrapperInterface
{
    /**
     * @var array<int, BootstrapperInterface>
     */
    private array $bootstrappers;

    /**
     * @var array<int, ServiceProviderInterface|class-string<ServiceProviderInterface>>
     */
    private array $providers;

    /**
     * @param array $providers     Additional service providers. Each entry
     *                             may be a ServiceProviderInterface instance
     *                             or a class-string of one.
     * @param array $bootstrappers Bootstrapper instances to run, in order.
     */
    public function __construct(array $providers = [], array $bootstrappers = [])
    {
        $this->providers = $providers;
        $this->bootstrappers = $bootstrappers;
    }

    public function bootstrap(Container $container): void
    {
        $this->registerProviders($container);

        foreach ($this->bootstrappers as $bootstrapper) {
            if ($bootstrapper instanceof BootstrapperInterface) {
                $bootstrapper->bootstrap($container);
            }
        }

        $this->bootProviders($container);
    }

    private function registerProviders(Container $container): void
    {
        $manager = new ServiceProviderManager($container);
        $container->singleton(ServiceProviderManager::class, fn() => $manager);

        $manager->add(new CoreServiceProvider());
        $manager->add(new ScheduleServiceProvider());

        foreach ($this->providers as $provider) {
            if (is_string($provider)) {
                $provider = new $provider();
            }
            if ($provider instanceof ServiceProviderInterface) {
                $manager->add($provider);
            }
        }

        $manager->registerAll();
    }

    private function bootProviders(Container $container): void
    {
        $manager = $container->get(ServiceProviderManager::class);
        $manager->bootAll();
    }
}
