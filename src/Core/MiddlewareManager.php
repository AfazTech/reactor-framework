<?php

namespace Reactor\Core;

use Reactor\Contracts\MiddlewareInterface;
use Reactor\Core\Container;
use Reactor\Core\ErrorHandler;
use Reactor\Contracts\LoggerInterface;
use Reactor\Enums\MiddlewareMode;

/**
 * Manages execution of middleware in the correct order (global, group, local).
 */
class MiddlewareManager
{
    private array $middlewares = [];
    private Container $container;
    private ErrorHandler $errorHandler;
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param Container       $container     DI container.
     * @param ErrorHandler    $errorHandler  Error handler.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(Container $container, ErrorHandler $errorHandler, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->errorHandler = $errorHandler;
        $this->logger = $logger;
    }

    /**
     * Set the list of available middleware and sort by priority.
     *
     * @param array<int, array{
     *     class: string,
     *     priority: int,
     *     mode: MiddlewareMode,
     *     groups: array,
     *     allowedUpdates: array
     * }> $middlewares
     */
    public function setMiddlewares(array $middlewares): void
    {
        $this->middlewares = $middlewares;
        usort($this->middlewares, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * Execute global middleware.
     *
     * @param array  $update      The update.
     * @param string $updateType  The update type.
     *
     * @return bool True if processing should stop.
     */
    public function executeGlobal(array $update, string $updateType): bool
    {
        return $this->executeByMode($update, $updateType, MiddlewareMode::GLOBAL);
    }

    /**
     * Execute group middleware that belong to any of the given groups.
     *
     * @param array  $update         The update.
     * @param string $updateType     The update type.
     * @param array  $handlerGroups  Groups from the handler.
     *
     * @return bool
     */
    public function executeGroup(array $update, string $updateType, array $handlerGroups): bool
    {
        $groupMiddlewares = array_filter($this->middlewares, function ($mw) use ($handlerGroups) {
            if ($mw['mode'] !== MiddlewareMode::GROUP) {
                return false;
            }
            if (empty($mw['groups'])) {
                return false;
            }
            return !empty(array_intersect($handlerGroups, $mw['groups']));
        });

        return $this->executeMiddlewares($update, $updateType, $groupMiddlewares);
    }

    /**
     * Execute local middleware explicitly specified by the handler.
     *
     * @param array  $update                 The update.
     * @param string $updateType             The update type.
     * @param array  $handlerUseMiddlewares  List of middleware class names.
     *
     * @return bool
     */
    public function executeLocal(array $update, string $updateType, array $handlerUseMiddlewares): bool
    {
        $localMiddlewares = array_filter($this->middlewares, function ($mw) use ($handlerUseMiddlewares) {
            if ($mw['mode'] !== MiddlewareMode::LOCAL) {
                return false;
            }
            return in_array($mw['class'], $handlerUseMiddlewares, true);
        });

        return $this->executeMiddlewares($update, $updateType, $localMiddlewares);
    }

    /**
     * Execute middleware of a specific mode.
     *
     * @param array         $update
     * @param string        $updateType
     * @param MiddlewareMode $mode
     *
     * @return bool
     */
    private function executeByMode(array $update, string $updateType, MiddlewareMode $mode): bool
    {
        $filtered = array_filter($this->middlewares, function ($mw) use ($mode) {
            return $mw['mode'] === $mode;
        });

        return $this->executeMiddlewares($update, $updateType, $filtered);
    }

    /**
     * Execute a list of middleware sequentially.
     *
     * @param array  $update       The update.
     * @param string $updateType   The update type.
     * @param array  $middlewares  List of middleware configurations.
     *
     * @return bool True if any middleware stops processing.
     */
    private function executeMiddlewares(array $update, string $updateType, array $middlewares): bool
    {
        $executedClasses = [];

        foreach ($middlewares as $mw) {
            $class = $mw['class'];

            // Prevent duplicate execution.
            if (in_array($class, $executedClasses, true)) {
                continue;
            }

            // Check allowed update types.
            if (!$this->allowsUpdateType($mw['allowedUpdates'], $updateType)) {
                continue;
            }

            try {
                $middleware = $this->container->get($class);
                if (!$middleware instanceof MiddlewareInterface) {
                    $this->logger->warning('Middleware must implement MiddlewareInterface', ['class' => $class]);
                    continue;
                }

                if ($middleware->handle($update)) {
                    $this->logger->debug('Middleware stopped processing', ['class' => $class]);
                    return true;
                }
            } catch (\Throwable $e) {
                $this->errorHandler->handleMiddlewareError($e, $update, $class);
                return true; // Stop on error.
            }

            $executedClasses[] = $class;
        }

        return false;
    }

    /**
     * Check if the middleware allows the given update type.
     *
     * @param array<string> $allowed List of allowed types ('any' means all).
     * @param string        $type    The actual update type.
     *
     * @return bool
     */
    private function allowsUpdateType(array $allowed, string $type): bool
    {
        return in_array('any', $allowed, true) || in_array($type, $allowed, true);
    }
}
