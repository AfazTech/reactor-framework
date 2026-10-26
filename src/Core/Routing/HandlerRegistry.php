<?php

namespace Reactor\Core\Routing;

use Amp\File;
use Reactor\Attributes\Text;
use Reactor\Attributes\Callback;
use Reactor\Attributes\Step;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Fallback;
use Reactor\Attributes\Group;
use Reactor\Attributes\UseMiddleware;
use Reactor\Attributes\Reply;
use Reactor\Core\Container;
use Reactor\Contracts\LoggerInterface;

/**
 * Registry that stores all discovered handlers and their metadata.
 *
 * Discovery uses amphp/file for directory listing so that it never
 * blocks the event loop.
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

    public function discover(string $directory, string $namespace): void
    {
        if (!File\isDirectory($directory)) {
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

            if (isset($this->discoveredClasses[$className])) {
                $this->logger->debug('Handler already discovered, skipping', ['class' => $className]);
                continue;
            }
            $this->discoveredClasses[$className] = true;

            $reflection = new \ReflectionClass($className);
            $metadata = $this->extractMetadata($reflection, $className);

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

            $callbackAttrs = $reflection->getAttributes(Callback::class);
            if (!empty($callbackAttrs)) {
                foreach ($callbackAttrs as $attr) {
                    $callbackAttr = $attr->newInstance();
                    $data = $callbackAttr->data;
                    $pattern = $callbackAttr->pattern ?? null;
                    $handlerData = [
                        'class'          => $className,
                        'isStep'         => $callbackAttr->isStep,
                        'allowedUpdates' => $allowedTypes,
                        'metadata'       => $metadata,
                    ];
                    if ($pattern !== null) {
                        $this->addPatternHandler(
                            $pattern,
                            $handlerData,
                            $callbackAttr->priority ?? 0,
                            'callback',
                            $data
                        );
                    } else {
                        $this->callbacks[$data] = $handlerData;
                    }
                    $this->logger->debug('Discovered callback handler', [
                        'data'  => $data,
                        'class' => $className,
                    ]);
                    $discoveredCount++;
                }
                continue;
            }

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
     * Recursively find every PHP file under the given directory.
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

            $stat = File\getStatus($path);
            if ($stat === null) {
                continue;
            }

            if ($stat['mode'] & 0040000) {
                $this->walk($path, $files);
                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
    }

    private function extractMetadata(\ReflectionClass $reflection, string $className): array
    {
        $metadata = [
            'groups'         => [],
            'useMiddlewares' => [],
            'requiresReply'  => null,
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

        $replyAttr = $reflection->getAttributes(Reply::class);
        if (!empty($replyAttr)) {
            $reply = $replyAttr[0]->newInstance();
            $metadata['requiresReply'] = $reply->only;
        }

        $this->handlerMetadata[$className] = $metadata;
        return $metadata;
    }

    private function getAttribute(\ReflectionClass $reflection, string $attributeClass): ?object
    {
        $attributes = $reflection->getAttributes($attributeClass);
        return empty($attributes) ? null : $attributes[0]->newInstance();
    }

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
        return $this->handlerMetadata[$className]
            ?? ['groups' => [], 'useMiddlewares' => [], 'requiresReply' => null];
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
            'metadata'       => ['groups' => [], 'useMiddlewares' => [], 'requiresReply' => null],
        ];
        $this->logger->debug('Step handler registered dynamically', ['name' => $stepName, 'class' => $handlerClass]);
    }

    public function setFallbackHandler(string $handlerClass, int $priority = 0): void
    {
        $this->registerFallbackCandidate(
            $handlerClass,
            $priority,
            ['groups' => [], 'useMiddlewares' => [], 'requiresReply' => null]
        );
    }
}
