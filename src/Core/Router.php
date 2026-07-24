<?php

namespace Reactor\Core;

use Reactor\Core\Routing\HandlerRegistry;
use Reactor\Core\Routing\HandlerMatcher;
use Reactor\Contracts\LoggerInterface;

/**
 * Main router that discovers handlers and matches incoming updates.
 */
class Router
{
    private HandlerRegistry $registry;
    private HandlerMatcher $matcher;
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param Container        $container DI container.
     * @param LoggerInterface  $logger    Logger.
     */
    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->registry = new HandlerRegistry($container, $logger);
        $this->matcher = new HandlerMatcher($this->registry, $container, $logger);
        $this->logger->debug('Router instance created');
    }

    /**
     * Discover handlers in a single directory.
     *
     * @param string $directory Directory path.
     * @param string $namespace Base namespace for classes.
     */
    public function discover(string $directory, string $namespace): void
    {
        $this->registry->discover($directory, $namespace);
    }

    /**
     * Discover handlers in multiple directories.
     *
     * @param array<int, array<string, string>> $sources List of ['directory' => ..., 'namespace' => ...].
     */
    public function discoverMany(array $sources): void
    {
        $this->registry->discoverMany($sources);
    }

    /**
     * Find a handler for the given update.
     *
     * @param array $update The Telegram update.
     *
     * @return HandlerExecution The execution result.
     */
    public function findHandler(array $update): HandlerExecution
    {
        return $this->matcher->findHandler($update);
    }

    // --- Delegation methods to registry ---

    /**
     * Get metadata for a handler class.
     *
     * @param string $className Handler class.
     *
     * @return array{groups: array, useMiddlewares: array}
     */
    public function getHandlerMetadata(string $className): array
    {
        return $this->registry->getHandlerMetadata($className);
    }

    /**
     * Get a step configuration by name.
     *
     * @param string $stepName Step name.
     *
     * @return array|null Step config or null if not found.
     */
    public function getStep(string $stepName): ?array
    {
        return $this->registry->getStep($stepName);
    }

    /**
     * Register a step handler dynamically.
     *
     * @param string $stepName       Step name.
     * @param string $handlerClass   Handler class.
     * @param string|null $nextStep  Next step after handling.
     * @param bool $autoClear        Whether to clear step after handling.
     * @param array<string> $allowedUpdates Allowed update types.
     */
    public function registerStepHandler(
        string $stepName,
        string $handlerClass,
        ?string $nextStep = null,
        bool $autoClear = true,
        array $allowedUpdates = ['any']
    ): void {
        $this->registry->registerStepHandler($stepName, $handlerClass, $nextStep, $autoClear, $allowedUpdates);
    }

    /**
     * Set the fallback handler.
     *
     * @param string $handlerClass Fallback handler class.
     * @param int    $priority     Priority.
     */
    public function setFallbackHandler(string $handlerClass, int $priority = 0): void
    {
        $this->registry->setFallbackHandler($handlerClass, $priority);
    }
}
