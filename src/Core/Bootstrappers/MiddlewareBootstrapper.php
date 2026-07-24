<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Core\Container;
use Reactor\Core\BootstrapperInterface;
use Reactor\Attributes\Middleware as MiddlewareAttr;
use Reactor\Enums\MiddlewareMode;
use Reactor\Contracts\LoggerInterface;

/**
 * Bootstrapper that discovers and registers middleware from source directories.
 */
class MiddlewareBootstrapper implements BootstrapperInterface
{
    private array $sources;

    public function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    public function bootstrap(Container $container): void
    {
        $logger = $container->get(LoggerInterface::class);
        $middlewares = [];
        $discoveredCount = 0;

        foreach ($this->sources as $source) {
            $directory = $source['directory'];
            $namespace = $source['namespace'];

            if (!is_dir($directory)) {
                $logger->warning("Middleware directory not found", ['directory' => $directory]);
                continue;
            }

            $files = glob($directory . '/*.php');
            foreach ($files as $file) {
                $relativePath = str_replace($directory . '/', '', $file);
                $className = $namespace . '\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);
                $middlewareAttr = $reflection->getAttributes(MiddlewareAttr::class);
                if (empty($middlewareAttr)) {
                    continue;
                }

                $attr = $middlewareAttr[0]->newInstance();
                $priority = $attr->priority;
                $mode = $attr->mode;
                $groups = $attr->groups;

                $onUpdateAttr = $reflection->getAttributes(\Reactor\Attributes\OnUpdate::class);
                $allowedTypes = ['any'];
                if (!empty($onUpdateAttr)) {
                    $onUpdate = $onUpdateAttr[0]->newInstance();
                    $allowedTypes = $onUpdate->types;
                }

                $middlewares[] = [
                    'class' => $className,
                    'priority' => $priority,
                    'mode' => $mode,
                    'groups' => $groups,
                    'allowedUpdates' => $allowedTypes
                ];
                $discoveredCount++;
                $logger->debug("Discovered middleware", [
                    'class' => $className,
                    'priority' => $priority,
                    'mode' => $mode->name,
                    'groups' => $groups
                ]);
            }
        }

        usort($middlewares, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        $container->singleton('middlewares', fn() => $middlewares);
        $logger->info("Middleware discovery completed", ['count' => $discoveredCount]);
    }
}
