<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Core\Container;
use Reactor\Core\BootstrapperInterface;
use Reactor\Core\Router;
use Reactor\Contracts\LoggerInterface;

/**
 * Bootstrapper that initialises the router and discovers handlers.
 */
class RouterBootstrapper implements BootstrapperInterface
{
    private array $sources;

    public function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    public function bootstrap(Container $container): void
    {
        $logger = $container->get(LoggerInterface::class);
        $router = $container->get(Router::class);
        $router->discoverMany($this->sources);
        $logger->info("Router initialized with " . count($this->sources) . " handler sources");
    }
}
