<?php

namespace Reactor\Core\Processing;

use Reactor\Core\MiddlewareManager;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Traits\FromIdExtractor;

/**
 * Orchestrates the execution of middleware in the correct order:
 * global → group → local.
 */
class MiddlewareProcessor
{
    use FromIdExtractor;

    private MiddlewareManager $middlewareManager;
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param MiddlewareManager $middlewareManager Middleware manager.
     * @param LoggerInterface   $logger            Logger.
     */
    public function __construct(MiddlewareManager $middlewareManager, LoggerInterface $logger)
    {
        $this->middlewareManager = $middlewareManager;
        $this->logger = $logger;
    }

    /**
     * Process all applicable middleware for the given update.
     *
     * @param array  $update      The update.
     * @param string $updateType  The update type.
     * @param array  $metadata    Handler metadata (groups, local middlewares).
     *
     * @return bool True if processing should stop (middleware halted).
     */
    public function process(array $update, string $updateType, array $metadata): bool
    {
        $handlerGroups = $metadata['groups'] ?? [];
        $handlerUseMiddlewares = $metadata['useMiddlewares'] ?? [];

        // Global middleware.
        if ($this->middlewareManager->executeGlobal($update, $updateType)) {
            $this->logger->debug('Global middleware stopped processing', ['user_id' => $this->extractFromIdIfExists($update)]);
            return true;
        }

        // Group middleware.
        if ($this->middlewareManager->executeGroup($update, $updateType, $handlerGroups)) {
            $this->logger->debug('Group middleware stopped processing', ['user_id' => $this->extractFromIdIfExists($update)]);
            return true;
        }

        // Local middleware.
        if ($this->middlewareManager->executeLocal($update, $updateType, $handlerUseMiddlewares)) {
            $this->logger->debug('Local middleware stopped processing', ['user_id' => $this->extractFromIdIfExists($update)]);
            return true;
        }

        return false;
    }
}
