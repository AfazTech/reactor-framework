<?php

namespace Reactor\Core\Processing;

use Reactor\Core\Container;
use Reactor\Core\HandlerExecution;
use Reactor\Core\ErrorHandler;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;
use Reactor\Events;
use Neili\Client;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\Traits\FromIdExtractor;

class HandlerInvoker
{
    use FromIdExtractor;

    private Container $container;
    private ErrorHandler $errorHandler;
    private LoggerInterface $logger;
    private EventDispatcher $dispatcher;
    private Client $client;
    private LanguageInterface $language;
    private UserProviderInterface $userProvider;

    public function __construct(
        Container $container,
        ErrorHandler $errorHandler,
        LoggerInterface $logger,
        EventDispatcher $dispatcher,
        Client $client,
        LanguageInterface $language,
        UserProviderInterface $userProvider
    ) {
        $this->container = $container;
        $this->errorHandler = $errorHandler;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->client = $client;
        $this->language = $language;
        $this->userProvider = $userProvider;
    }

    public function invoke(HandlerExecution $execution): void
    {
        $handlerClass = $execution->handlerClass;
        $handler = $this->container->get($handlerClass);

        // Missing execute() is a wiring/configuration bug, not a runtime
        // failure of the handler itself, so it must propagate instead of
        // being swallowed by the catch block below.
        if (!method_exists($handler, 'execute')) {
            throw new \RuntimeException("Handler {$handlerClass} must have an execute() method.");
        }

        try {
            $handler->execute($execution->update, $execution->params);

            $this->logger->debug('Handler executed successfully', ['class' => $handlerClass]);
            $this->dispatcher->dispatch(Events::HANDLER_EXECUTED, $handlerClass, $execution->update);
        } catch (\Throwable $e) {
            $this->errorHandler->handleException($e, [
                'handler' => $execution->handlerClass,
                'update'  => $execution->update,
            ]);

            $fromId = $this->extractFromIdIfExists($execution->update);
            if ($fromId) {
                $lang = $this->language->getDefaultLanguage();
                try {
                    $lang = $this->userProvider->getLanguage($fromId);
                } catch (\Throwable $ignored) {
                    // Fallback to default language if provider fails.
                }
                $message = $this->language->get('error', $lang);
                $this->client->sendMessage($fromId, $message);
            }
        }
    }
}
