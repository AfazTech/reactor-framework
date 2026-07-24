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
    private Container $container;
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param Container        $container DI container.
     * @param LoggerInterface  $logger    Logger.
     */
    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Discover handlers in a single directory.
     *
     * @param string $directory Directory path.
     * @param string $namespace Base namespace.
     */
    public function discover(string $directory, string $namespace): void
    {
        if (!is_dir($directory)) {
            $this->logger->warning('Router discovery directory not found', ['directory' => $directory]);
            return;
        }

        $files = glob($directory . '/*.php');
        $discoveredCount = 0;
        $directory = rtrim($directory, '/');

        foreach ($files as $file) {
            $relativePath = str_replace($directory . '/', '', $file);
            $className = $namespace . '\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);
            $metadata = $this->extractMetadata($reflection, $className);

            // Fallback handler.
            $fallbackAttr = $reflection->getAttributes(Fallback::class);
            if (!empty($fallbackAttr)) {
                $fallback = $fallbackAttr[0]->newInstance();
                $this->fallbackHandler = [
                    'class'    => $className,
                    'priority' => $fallback->priority,
                    'metadata' => $metadata,
                ];
                $this->logger->debug('Discovered fallback handler', ['class' => $className]);
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
            $this->logger->debug('Router discovered handlers', ['directory' => basename($directory), 'count' => $discoveredCount]);
        }
    }

    /**
     * Discover handlers in multiple directories.
     *
     * @param array<int, array<string, string>> $sources List of ['directory' => ..., 'namespace' => ...].
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
     * Extract metadata from a handler class.
     *
     * @param \ReflectionClass $reflection
     * @param string           $className
     *
     * @return array{groups: array, useMiddlewares: array}
     */
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

    /**
     * Get a single attribute instance from a reflection class.
     *
     * @param \ReflectionClass $reflection
     * @param string           $attributeClass
     *
     * @return object|null
     */
    private function getAttribute(\ReflectionClass $reflection, string $attributeClass): ?object
    {
        $attributes = $reflection->getAttributes($attributeClass);
        return empty($attributes) ? null : $attributes[0]->newInstance();
    }

    /**
     * Add a regex pattern handler.
     *
     * @param string $pattern     The regex pattern.
     * @param array  $handlerData Handler configuration.
     * @param int    $priority    Priority.
     * @param string $type        'text' or 'callback'.
     * @param string $name        Original name for logging.
     */
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

    // --- Getters ---

    /**
     * @return array
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @return array
     */
    public function getTexts(): array
    {
        return $this->texts;
    }

    /**
     * @return array
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * @return array
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @return array
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * @return array|null
     */
    public function getFallbackHandler(): ?array
    {
        return $this->fallbackHandler;
    }

    /**
     * Get metadata for a handler class.
     *
     * @param string $className
     *
     * @return array{groups: array, useMiddlewares: array}
     */
    public function getHandlerMetadata(string $className): array
    {
        return $this->handlerMetadata[$className] ?? ['groups' => [], 'useMiddlewares' => []];
    }

    /**
     * Get a step by name.
     *
     * @param string $stepName
     *
     * @return array|null
     */
    public function getStep(string $stepName): ?array
    {
        return $this->steps[$stepName] ?? null;
    }

    // --- Dynamic registration ---

    /**
     * Register a step handler dynamically.
     *
     * @param string        $stepName
     * @param string        $handlerClass
     * @param string|null   $nextStep
     * @param bool          $autoClear
     * @param array<string> $allowedUpdates
     */
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

    /**
     * Set the fallback handler dynamically.
     *
     * @param string $handlerClass
     * @param int    $priority
     */
    public function setFallbackHandler(string $handlerClass, int $priority = 0): void
    {
        $this->fallbackHandler = [
            'class'    => $handlerClass,
            'priority' => $priority,
            'metadata' => ['groups' => [], 'useMiddlewares' => []],
        ];
        $this->logger->debug('Fallback handler set dynamically', ['class' => $handlerClass]);
    }
}
