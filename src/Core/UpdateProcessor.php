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

            if (!$execution->hasHandler()) {
                $this->unknownCommandHandler->handle($update);
                $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'no_handler');
                return;
            }

            if ($this->middlewareProcessor->process($update, $updateType, $execution->metadata)) {
                $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'stopped_by_middleware');
                return;
            }

            $this->handlerInvoker->invoke($execution);
            $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'success');
        } catch (\Throwable $e) {
            $this->errorHandler->handleException($e, ['update' => $update]);
            $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, 'error');
        }

        $this->logger->info('=== PROCESSING COMPLETED ===', ['type' => $updateType]);
    }
}
