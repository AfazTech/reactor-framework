<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Core\Container;
use Reactor\Core\BootstrapperInterface;
use Reactor\Core\ServiceProviderManager;
use Reactor\Core\Providers\CoreServiceProvider;
use Reactor\Core\Providers\ScheduleServiceProvider;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\MiddlewareManager;
use Reactor\Core\ErrorHandler;
use Reactor\Core\UpdateProcessor;

/**
 * Main bootstrapper that orchestrates the entire application bootstrap process.
 */
class AppBootstrapper implements BootstrapperInterface
{
    private array $providers;
    private array $handlerSources;
    private array $middlewareSources;

    public function __construct(array $providers = [], array $handlerSources = [], array $middlewareSources = [])
    {
        $this->providers = $providers;
        $this->handlerSources = $handlerSources;
        $this->middlewareSources = $middlewareSources;
    }

    public function bootstrap(Container $container): void
    {
        $this->registerProviders($container);
        $this->bootstrapDatabase($container);
        $this->bootstrapMiddleware($container);
        $this->bootstrapRouter($container);
        $this->bootProviders($container);
        $this->finalize($container);
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

    private function bootstrapDatabase(Container $container): void
    {
        $bootstrapper = new DatabaseBootstrapper();
        $bootstrapper->bootstrap($container);
    }

    private function bootstrapMiddleware(Container $container): void
    {
        $bootstrapper = new MiddlewareBootstrapper($this->middlewareSources);
        $bootstrapper->bootstrap($container);
    }

    private function bootstrapRouter(Container $container): void
    {
        $bootstrapper = new RouterBootstrapper($this->handlerSources);
        $bootstrapper->bootstrap($container);
    }

    private function bootProviders(Container $container): void
    {
        $manager = $container->get(ServiceProviderManager::class);
        $manager->bootAll();
    }

    private function finalize(Container $container): void
    {
        $middlewareManager = $container->get(MiddlewareManager::class);
        $middlewares = $container->get('middlewares') ?? [];
        $middlewareManager->setMiddlewares($middlewares);

        $container->get(ErrorHandler::class);
        $container->get(UpdateProcessor::class);
    }
}
