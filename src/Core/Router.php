<?php

namespace Reactor\Core;

use Reactor\Core\Routing\CommandParser;
use Reactor\Core\Routing\HandlerRegistry;
use Reactor\Core\Routing\HandlerMatcher;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;

/**
 * Main router that discovers handlers and matches incoming updates.
 */
class Router
{
    private HandlerRegistry $registry;
    private HandlerMatcher $matcher;
    private LoggerInterface $logger;

    public function __construct(
        Container $container,
        LoggerInterface $logger,
        LanguageInterface $language,
        UserProviderInterface $userProvider,
        UpdateTypeResolver $updateTypeResolver,
        CommandParser $commandParser
    ) {
        $this->logger = $logger;
        $this->registry = new HandlerRegistry($container, $logger);
        $this->matcher = new HandlerMatcher(
            $this->registry,
            $container,
            $logger,
            $language,
            $userProvider,
            $updateTypeResolver,
            $commandParser
        );
        $this->logger->debug('Router instance created');
    }

    /**
     * Discover handlers in a single directory.
     */
    public function discover(string $directory, string $namespace): void
    {
        $this->registry->discover($directory, $namespace);
    }

    /**
     * Discover handlers in multiple directories.
     *
     * @param array<int, array<string, string>> $sources
     */
    public function discoverMany(array $sources): void
    {
        $this->registry->discoverMany($sources);
    }

    /**
     * Find a handler for the given update.
     */
    public function findHandler(array $update): HandlerExecution
    {
        return $this->matcher->findHandler($update);
    }

    /**
     * Get metadata for a handler class.
     *
     * @return array{groups: array, useMiddlewares: array}
     */
    public function getHandlerMetadata(string $className): array
    {
        return $this->registry->getHandlerMetadata($className);
    }

    /**
     * Get a step configuration by name.
     */
    public function getStep(string $stepName): ?array
    {
        return $this->registry->getStep($stepName);
    }

    /**
     * Register a step handler dynamically.
     *
     * @param array<string> $allowedUpdates
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
     */
    public function setFallbackHandler(string $handlerClass, int $priority = 0): void
    {
        $this->registry->setFallbackHandler($handlerClass, $priority);
    }
}
