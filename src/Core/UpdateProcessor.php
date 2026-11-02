<?php

namespace Reactor\Core;

use Neili\Client;
use Reactor\Callbacks\CallbackStore;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\Processing\HandlerInvoker;
use Reactor\Core\Processing\MiddlewareProcessor;
use Reactor\Core\Traits\FromIdExtractor;
use Reactor\Diagnostics\RuntimeMetrics;
use Reactor\Events;
use Reactor\RateLimit\ThrottleGuard;

/** Coordinates update ordering, scoped state, routing, throttling and observability. */
class UpdateProcessor
{
    use FromIdExtractor;
    public function __construct(
        private Container $container,
        private Router $router,
        private MiddlewareProcessor $middlewareProcessor,
        private HandlerInvoker $handlerInvoker,
        private ErrorHandler $errorHandler,
        private LoggerInterface $logger,
        private EventDispatcher $dispatcher,
        private Client $client,
        private LanguageInterface $language,
        private UserProviderInterface $userProvider,
        private UpdateTypeResolver $updateTypeResolver,
        private UnknownCommandHandler $unknownCommandHandler,
        private ?UpdateScope $updateScope = null,
        private ?UpdateSequencer $sequencer = null,
        private ?ThrottleGuard $throttle = null,
        private ?CallbackStore $callbacks = null,
        private ?RuntimeMetrics $metrics = null,
    ) {}

    public function process(array $update): void
    {
        $this->updateScope ??= new UpdateScope();
        $this->sequencer ??= new UpdateSequencer();
        $start = hrtime(true); $status = 'fatal_error';
        $this->metrics?->begin();
        try {
            $status = $this->sequencer->run($update,
                fn () => $this->updateScope->run(fn () => $this->processWithinScope($update)));
        } finally {
            $this->metrics?->finish($status, (hrtime(true) - $start) / 1e9);
        }
    }

    private function processWithinScope(array $update): string
    {
        $updateType = $this->updateTypeResolver->getType($update);
        try {
            $this->logger->info('=== PROCESSING UPDATE ===', ['type' => $updateType]);
            $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_STARTED, $update);
            $token = $update['callback_query']['data'] ?? null;
            if ($this->callbacks !== null && is_string($token) && str_starts_with($token, 'rcb:')) {
                $payload = $this->callbacks->resolve($token, $this->fromId($update));
                if ($payload === null) {
                    $this->client->answerCallbackQuery($update['callback_query']['id'], 'This button is invalid or expired.')->await();
                    return $this->finish($update, 'invalid_callback');
                }
                $update['reactor']['callback_payload'] = $payload['data'];
                $update['reactor']['callback_token'] = $token;
                $update['callback_query']['data'] = $payload['action'];
            }
            $execution = $this->router->findHandler($update);
            if (!$execution->hasHandler()) {
                $this->unknownCommandHandler->handle($update);
                return $this->finish($update, 'built_in_fallback');
            }
            if ($this->throttle !== null && isset($execution->metadata['throttle'])) {
                $limit = $this->throttle->check($execution->handlerClass, $update, $execution->metadata['throttle']);
                if (!$limit->allowed) {
                    $this->dispatcher->dispatch(Events::UPDATE_THROTTLED, $update, $limit->retryAfter);
                    if (isset($update['callback_query']['id'])) {
                        $this->client->answerCallbackQuery($update['callback_query']['id'], 'Too many requests. Try again shortly.')->await();
                    }
                    return $this->finish($update, 'throttled');
                }
            }
            if ($this->middlewareProcessor->process($update, $updateType, $execution->metadata)) {
                return $this->finish($update, 'stopped_by_middleware');
            }
            $success = $this->handlerInvoker->invoke($execution);
            return $this->finish($update, $success ? 'success' : 'handler_error');
        } catch (\Error $e) {
            $this->logger->error('Fatal error while processing update: ' . $e->getMessage(), [
                'exception' => get_class($e), 'trace' => $e->getTraceAsString(),
            ]);
            $this->finish($update, 'fatal_error');
            throw $e;
        } catch (\Throwable $e) {
            $this->errorHandler->handleException($e, ['update' => $update]);
            return $this->finish($update, 'error');
        }
    }

    private function finish(array $update, string $status): string
    {
        $this->dispatcher->dispatch(Events::UPDATE_PROCESSING_FINISHED, $update, $status);
        return $status;
    }
}
