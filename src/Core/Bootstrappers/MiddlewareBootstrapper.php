<?php

namespace Reactor\Core\Bootstrappers;

use Amp\File;
use Reactor\Core\Container;
use Reactor\Core\BootstrapperInterface;
use Reactor\Attributes\Middleware as MiddlewareAttr;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Reply;
use Reactor\Contracts\LoggerInterface;

/**
 * Bootstrapper that discovers and registers middleware from source directories.
 *
 * Discovery uses amphp/file for directory listing so that it never
 * blocks the event loop.
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

            if (!File\isDirectory($directory)) {
                $logger->warning('Middleware directory not found', ['directory' => $directory]);
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

                $replyAttr = $reflection->getAttributes(Reply::class);
                $requiresReply = null;
                if (!empty($replyAttr)) {
                    $requiresReply = $replyAttr[0]->newInstance()->only;
                }

                $middlewares[] = [
                    'class'          => $className,
                    'priority'       => $priority,
                    'mode'           => $mode,
                    'groups'         => $groups,
                    'allowedUpdates' => $allowedTypes,
                    'requiresReply'  => $requiresReply,
                ];
                $discoveredCount++;
                $logger->debug('Discovered middleware', [
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
        $logger->info('Middleware discovery completed', ['count' => $discoveredCount]);
    }

    /**
     * Recursively find all PHP files under the given directory.
     *
     * @return array<int, string> Sorted list of absolute file paths.
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        $this->walk($directory, $files);
        sort($files);
        return $files;
    }

    private function walk(string $directory, array &$files): void
    {
        foreach (File\listFiles($directory) as $entry) {
            $path = $directory . '/' . $entry;

            if (File\isDirectory($path)) {
                $this->walk($path, $files);
                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
    }
}
