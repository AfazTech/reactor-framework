<?php

namespace Reactor\Core\Routing;

use Reactor\Attributes\Text;
use Reactor\Attributes\Callback;
use Reactor\Attributes\Step;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Fallback;
use Reactor\Attributes\Group;
use Reactor\Attributes\UseMiddleware;
use Reactor\Core\Container;
use Reactor\Contracts\LoggerInterface;

/**
 * Registry that stores all discovered handlers and their metadata.
 *
 * Discovery is recursive, so a single root source (e.g. `App\Handlers`)
 * is enough to cover nested subdirectories such as `Handlers\Admin`.
 * Classes are tracked in `$discoveredClasses` so that overlapping or
 * duplicated sources (root + subdirectory, identical entries in the
 * sources list, re-discovery after a reload) do not register the same
 * handler twice.
 */
class HandlerRegistry
{
    private array $commands = [];
    private array $texts = [];
    private array $callbacks = [];
    private array $steps = [];
    private ?array $fallbackHandler = null;
    private array $patterns = [];
    private array $handlerMetadata = [];

    /**
     * Fully-qualified class names already processed, used for de-duplication.
     *
     * @var array<string, true>
     */
    private array $discoveredClasses = [];

    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Discover handlers in a single directory (recursively).
     *
     * PHP files in nested subdirectories are discovered as well; the
     * subdirectory name becomes part of the class namespace.
     */
    public function discover(string $directory, string $namespace): void
    {
        if (!is_dir($directory)) {
            $this->logger->warning('Router discovery directory not found', ['directory' => $directory]);
            return;
        }

        $directory = rtrim($directory, '/');
        $files = $this->findPhpFiles($directory);
        $discoveredCount = 0;

        foreach ($files as $file) {
            $relativePath = substr($file, strlen($directory) + 1);
            $className = $namespace . '\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (!class_exists($className)) {
                continue;
            }

            // Skip classes already registered through a previous source.
            // This protects against overlapping sources (parent + child)
            // and against calling discover() twice on the same tree.
            if (isset($this->discoveredClasses[$className])) {
                $this->logger->debug('Handler already discovered, skipping', ['class' => $className]);
                continue;
            }
            $this->discoveredClasses[$className] = true;

            $reflection = new \ReflectionClass($className);
            $metadata = $this->extractMetadata($reflection, $className);

            // Fallback handler.
            $fallbackAttr = $reflection->getAttributes(Fallback::class);
            if (!empty($fallbackAttr)) {
                $fallback = $fallbackAttr[0]->newInstance();
                $this->registerFallbackCandidate($className, $fallback->priority, $metadata);
                $discoveredCount++;
                continue;
            }

            $onUpdateAttr = $reflection->getAttributes(OnUpdate::class);
            $allowedTypes = ['any'];
            if (!empty($onUpdateAttr)) {
                $onUpdate = $onUpdateAttr[0]->newInstance();
                $allowedTypes = $onUpdate->types;
            }

            // Step handler.
            $stepAttr = $this->getAttribute($reflection, Step::class);
            if ($stepAttr) {
                $this->steps[$stepAttr->name] = [
                    'class'          => $className,
                    'next'           => $stepAttr->nextStep,
                    'autoClear'      => $stepAttr->autoClear,
                    'allowedUpdates' => $allowedTypes,
                    'metadata'       => $metadata,
                ];
                $this->logger->debug('Discovered step handler', ['name' => $stepAttr->name, 'class' => $className]);
                $discoveredCount++;
                continue;
            }

            // Callback handler.
            $callbackAttr = $this->getAttribute($reflection, Callback::class);
            if ($callbackAttr) {
                $data = $callbackAttr->data;
                $pattern = $callbackAttr->pattern ?? null;
                $handlerData = [
                    'class'          => $className,
                    'isStep'         => $callbackAttr->isStep,
                    'allowedUpdates' => $allowedTypes,
                    'metadata'       => $metadata,
                ];
                if ($pattern !== null) {
                    $this->addPatternHandler($pattern, $handlerData, $callbackAttr->priority ?? 0, 'callback', $data);
                } else {
                    $this->callbacks[$data] = $handlerData;
                }
                $this->logger->debug('Discovered callback handler', ['data' => $data, 'class' => $className]);
                $discoveredCount++;
                continue;
            }

            // Text/command handlers.
            $textAttrs = $reflection->getAttributes(Text::class);
            if (!empty($textAttrs)) {
                foreach ($textAttrs as $attr) {
                    $textAttr = $attr->newInstance();
                    $handlerData = [
                        'class'          => $className,
                        'priority'       => $textAttr->priority,
                        'allowedUpdates' => $allowedTypes,
                        'metadata'       => $metadata,
                    ];

                    if ($textAttr->pattern !== null) {
                        $this->addPatternHandler($textAttr->pattern, $handlerData, $textAttr->priority, 'text', $textAttr->name);
                    } else {
                        if ($textAttr->isCommand) {
                            $key = $textAttr->name;
                            if (isset($this->commands[$key]) && $this->commands[$key]['priority'] >= $textAttr->priority) {
                                $this->logger->warning('Duplicate command handler dropped', [
                                    'command'        => $key,
                                    'dropped_class'  => $className,
                                    'existing_class' => $this->commands[$key]['class'],
                                    'priority'       => $textAttr->priority,
                                ]);
                                continue;
                            }
                            $this->commands[$key] = $handlerData;
                            $this->logger->debug('Discovered command handler', [
                                'name'     => $key,
                                'class'    => $className,
                                'priority' => $textAttr->priority,
                            ]);
                        } else {
                            $key = $textAttr->name;
                            if (isset($this->texts[$key]) && $this->texts[$key]['priority'] >= $textAttr->priority) {
                                $this->logger->warning('Duplicate text/button handler dropped', [
                                    'text_key'       => $key,
                                    'dropped_class'  => $className,
                                    'existing_class' => $this->texts[$key]['class'],
                                    'priority'       => $textAttr->priority,
                                ]);
                                continue;
                            }
                            $this->texts[$key] = $handlerData;
                            $this->logger->debug('Discovered text/button handler', [
                                'name'     => $key,
                                'class'    => $className,
                                'priority' => $textAttr->priority,
                            ]);
                        }
                    }
                    $discoveredCount++;
                }
            }
        }

        if ($discoveredCount > 0) {
            $this->logger->debug('Router discovered handlers', [
                'directory' => basename($directory),
                'count'     => $discoveredCount,
            ]);
        }
    }

    /**
     * Discover handlers in multiple directories.
     *
     * @param array<int, array<string, string>> $sources
     */
    public function discoverMany(array $sources): void
    {
        foreach ($sources as $source) {
            if (!isset($source['directory']) || !isset($source['namespace'])) {
                $this->logger->warning('Invalid source entry, skipping', ['source' => $source]);
                continue;
            }
            $this->discover($source['directory'], $source['namespace']);
        }
    }

    /**
     * Recursively find all PHP files under the given directory.
     *
     * The result is sorted so that discovery order is deterministic
     * (which matters when two handlers share the same priority).
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

    private function extractMetadata(\ReflectionClass $reflection, string $className): array
    {
        $metadata = [
            'groups'          => [],
            'useMiddlewares'  => [],
        ];

        $groupAttr = $reflection->getAttributes(Group::class);
        if (!empty($groupAttr)) {
            $group = $groupAttr[0]->newInstance();
            $groups = is_array($group->groups) ? $group->groups : [$group->groups];
            $metadata['groups'] = $groups;
        }

        $useAttr = $reflection->getAttributes(UseMiddleware::class);
        if (!empty($useAttr)) {
            $use = $useAttr[0]->newInstance();
            $metadata['useMiddlewares'] = $use->middlewares;
        }

        $this->handlerMetadata[$className] = $metadata;
        return $metadata;
    }

    private function getAttribute(\ReflectionClass $reflection, string $attributeClass): ?object
    {
        $attributes = $reflection->getAttributes($attributeClass);
        return empty($attributes) ? null : $attributes[0]->newInstance();
    }

    /**
     * Register a fallback candidate, keeping only the highest-priority one.
     *
     * Higher priority wins, matching the #[Fallback] attribute contract.
     * Ties are resolved in favor of the first discovered class, which is
     * deterministic because file listing is sorted.
     */
    private function registerFallbackCandidate(string $className, int $priority, array $metadata): void
    {
        $candidate = [
            'class'    => $className,
            'priority' => $priority,
            'metadata' => $metadata,
        ];

        if ($this->fallbackHandler === null) {
            $this->fallbackHandler = $candidate;
            $this->logger->debug('Discovered fallback handler', [
                'class'    => $className,
                'priority' => $priority,
            ]);
            return;
        }

        if ($priority > $this->fallbackHandler['priority']) {
            $this->logger->debug('Fallback handler replaced by higher priority', [
                'new_class'    => $className,
                'new_priority' => $priority,
                'old_class'    => $this->fallbackHandler['class'],
                'old_priority' => $this->fallbackHandler['priority'],
            ]);
            $this->fallbackHandler = $candidate;
            return;
        }

        $this->logger->warning('Duplicate fallback handler dropped (lower or equal priority)', [
            'dropped_class' => $className,
            'kept_class'    => $this->fallbackHandler['class'],
            'kept_priority' => $this->fallbackHandler['priority'],
        ]);
    }

    private function addPatternHandler(string $pattern, array $handlerData, int $priority, string $type, string $name): void
    {
        if (@preg_match($pattern, '') === false) {
            $this->logger->warning('Invalid regex pattern, skipping handler', [
                'pattern' => $pattern,
                'class'   => $handlerData['class'],
                'type'    => $type,
                'name'    => $name,
            ]);
            return;
        }

        $this->patterns[] = [
            'pattern'  => $pattern,
            'handler'  => $handlerData,
            'priority' => $priority,
            'type'     => $type,
            'name'     => $name,
        ];

        usort($this->patterns, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        $this->logger->debug('Discovered pattern handler', [
            'pattern'  => $pattern,
            'class'    => $handlerData['class'],
            'priority' => $priority,
            'type'     => $type,
        ]);
    }

    public function getCommands(): array
    {
        return $this->commands;
    }

    public function getTexts(): array
    {
        return $this->texts;
    }

    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getPatterns(): array
    {
        return $this->patterns;
    }

    public function getFallbackHandler(): ?array
    {
        return $this->fallbackHandler;
    }

    public function getHandlerMetadata(string $className): array
    {
        return $this->handlerMetadata[$className] ?? ['groups' => [], 'useMiddlewares' => []];
    }

    public function getStep(string $stepName): ?array
    {
        return $this->steps[$stepName] ?? null;
    }

    public function registerStepHandler(
        string $stepName,
        string $handlerClass,
        ?string $nextStep = null,
        bool $autoClear = true,
        array $allowedUpdates = ['any']
    ): void {
        $this->steps[$stepName] = [
            'class'          => $handlerClass,
            'next'           => $nextStep,
            'autoClear'      => $autoClear,
            'allowedUpdates' => $allowedUpdates,
            'metadata'       => ['groups' => [], 'useMiddlewares' => []],
        ];
        $this->logger->debug('Step handler registered dynamically', ['name' => $stepName, 'class' => $handlerClass]);
    }

    public function setFallbackHandler(string $handlerClass, int $priority = 0): void
    {
        $this->registerFallbackCandidate(
            $handlerClass,
            $priority,
            ['groups' => [], 'useMiddlewares' => []]
        );
    }
}
