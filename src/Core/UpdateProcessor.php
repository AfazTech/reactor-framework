<?php

namespace Reactor\Core;

use Reactor\Core\Container;
use Reactor\Core\Router;
use Reactor\Core\Processing\MiddlewareProcessor;
use Reactor\Core\Processing\HandlerInvoker;
use Reactor\Core\ErrorHandler;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;
use Neili\Client;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\UpdateTypeResolver;
use Reactor\Core\UnknownCommandHandler;
use Reactor\Events;

/**
 * Orchestrates the processing of a Telegram update.
 *
 * Fallback precedence when no handler matches:
 *
 *   1. Application-level #[Fallback] handler, if discovered by the
 *      Router. Its priority determines which one is chosen when
 *      multiple fallbacks exist (higher priority wins).
 *   2. Framework-level UnknownCommandHandler, which replies with the
 *      translated "unknown_command" message to the sender.
 *
 * Because Router::findHandler() returns the #[Fallback] class as a
 * regular HandlerExecution when one is registered, the two tiers are
 * mutually exclusive: the built-in default only runs when the router
 * could not find *any* handler at all.
 */
class UpdateProcessor
{
    private Container $container;
    private Router $router;
    private MiddlewareProcessor $middlewareProcessor;
    private HandlerInvoker $handlerInvoker;
    private ErrorHandler $errorHandler;
    private LoggerInterface $logger;
    private EventDispatcher $dispatcher;
    private Client $client;
    private LanguageInterface $language;
    private UserProviderInterface $userProvider;
    private UpdateTypeResolver $updateTypeResolver;
    private UnknownCommandHandler $unknownCommandHandler;

    public function __construct(
        Container $container,
        Router $router,
        MiddlewareProcessor $middlewareProcessor,
        HandlerInvoker $handlerInvoker,
        ErrorHandler $errorHandler,
        LoggerInterface $logger,
        EventDispatcher $dispatcher,
        Client $client,
        LanguageInterface $language,
        UserProviderInterface $userProvider,
        UpdateTypeResolver $updateTypeResolver,
        UnknownCommandHandler $unknownCommandHandler
    ) {
        $this->container = $container;
        $this->router = $router;
        $this->middlewareProcessor = $middlewareProcessor;
        $this->handlerInvoker = $handlerInvoker;
        $this->errorHandler = $errorHandler;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->client = $client;
        $this->language = $language;
        $this->userProvider = $userProvider;
        $this->updateTypeResolver = $updateTypeResolver;
        $this->unknownCommandHandler = $unknownCommandHandler;
    }

    public function process(array $update): void
    {
        $updateType = $this->updateTypeResolver->getType($update);

        $this->logger->info('=== PROCESSING UPDATE ===', ['type' => $updateType]);

        $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_STARTED, $update);

        try {
            $execution = $this->router->findHandler($update);

            // No handler at all: neither an explicit match nor a
            // #[Fallback] handler was registered by the application.
            // Delegate to the framework's built-in default response.
            if (!$execution->hasHandler()) {
                $this->logger->debug('No handler matched, using built-in UnknownCommandHandler');
                $this->unknownCommandHandler->handle($update);
                $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'built_in_fallback');
                return;
            }

            if ($this->middlewareProcessor->process($update, $updateType, $execution->metadata)) {
                $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'stopped_by_middleware');
                return;
            }

            $this->handlerInvoker->invoke($execution);
            $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'success');
        } catch (\Error $e) {
            // \Error subclasses (TypeError, ArgumentCountError, etc.) indicate
            // a wiring/code bug, not a runtime failure we can gracefully
            // recover from. Log, dispatch and rethrow so the outer loop can
            // decide how to handle it.
            $this->logger->error('Fatal error while processing update: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'update'    => $update,
                'trace'     => $e->getTraceAsString(),
            ]);
            $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'fatal_error');
            $this->logger->info('=== PROCESSING COMPLETED ===', ['type' => $updateType, 'status' => 'fatal_error']);
            throw $e;
        } catch (\Throwable $e) {
            $this->errorHandler->handleException($e, ['update' => $update]);
            $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'error');
        }

        $this->logger->info('=== PROCESSING COMPLETED ===', ['type' => $updateType]);
    }
}
