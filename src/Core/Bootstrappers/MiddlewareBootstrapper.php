<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Core\Container;
use Reactor\Core\BootstrapperInterface;
use Reactor\Attributes\Middleware as MiddlewareAttr;
use Reactor\Attributes\OnUpdate;
use Reactor\Enums\MiddlewareMode;
use Reactor\Contracts\LoggerInterface;

/**
 * Bootstrapper that discovers and registers middleware from source directories.
 *
 * Discovery is recursive: nested subdirectories contribute to the class
 * namespace in the same way PHP's PSR-4 autoloader would resolve them.
 *
 * Middleware classes are tracked in `$seenClasses` so that overlapping
 * sources (root + subdirectory, or a source listed twice) do not cause
 * the same middleware to be registered twice in the pipeline.
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

        /** @var array<string, true> $seenClasses */
        $seenClasses = [];

        foreach ($this->sources as $source) {
            $directory = rtrim($source['directory'], '/');
            $namespace = $source['namespace'];

            if (!is_dir($directory)) {
                $logger->warning("Middleware directory not found", ['directory' => $directory]);
                continue;
            }

            $files = $this->findPhpFiles($directory);

            foreach ($files as $file) {
                $relativePath = substr($file, strlen($directory) + 1);
                $className = $namespace . '\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (!class_exists($className)) {
                    continue;
                }

                if (isset($seenClasses[$className])) {
                    $logger->debug('Middleware already discovered, skipping', ['class' => $className]);
                    continue;
                }

                $reflection = new \ReflectionClass($className);
                $middlewareAttr = $reflection->getAttributes(MiddlewareAttr::class);
                if (empty($middlewareAttr)) {
                    continue;
                }

                $seenClasses[$className] = true;

                $attr = $middlewareAttr[0]->newInstance();
                $priority = $attr->priority;
                $mode = $attr->mode;
                $groups = $attr->groups;

                $onUpdateAttr = $reflection->getAttributes(OnUpdate::class);
                $allowedTypes = ['any'];
                if (!empty($onUpdateAttr)) {
                    $onUpdate = $onUpdateAttr[0]->newInstance();
                    $allowedTypes = $onUpdate->types;
                }

                $middlewares[] = [
                    'class'          => $className,
                    'priority'       => $priority,
                    'mode'           => $mode,
                    'groups'         => $groups,
                    'allowedUpdates' => $allowedTypes,
                ];
                $discoveredCount++;
                $logger->debug("Discovered middleware", [
                    'class'    => $className,
                    'priority' => $priority,
                    'mode'     => $mode->name,
                    'groups'   => $groups,
                ]);
            }
        }

        usort($middlewares, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        $container->singleton('middlewares', fn() => $middlewares);
        $logger->info("Middleware discovery completed", ['count' => $discoveredCount]);
    }

    /**
     * Recursively find all PHP files under the given directory.
     *
     * @return array<int, string> Sorted list of absolute file paths.
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }
}
