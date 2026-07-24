<?php

namespace Reactor\Core;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;
use Reactor\Events;
use Neili\Client;
use Reactor\Core\Config;
use Reactor\Contracts\LanguageInterface;
use Reactor\Core\Traits\FromIdExtractor;

/**
 * Centralised error handler for the application.
 *
 * Distinguishes between system errors (logged, possibly alerted) and
 * user‑friendly errors (which also send a message to the user).
 */
class ErrorHandler
{
    use FromIdExtractor;

    private LoggerInterface $logger;
    private EventDispatcher $dispatcher;
    private Client $client;
    private Config $config;
    private LanguageInterface $language;

    /**
     * Constructor.
     *
     * @param LoggerInterface   $logger     Logger instance.
     * @param EventDispatcher   $dispatcher Event dispatcher.
     * @param Client            $client     Telegram client.
     * @param Config            $config     Application config.
     * @param LanguageInterface $language   Translation manager.
     */
    public function __construct(
        LoggerInterface $logger,
        EventDispatcher $dispatcher,
        Client $client,
        Config $config,
        LanguageInterface $language
    ) {
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->client = $client;
        $this->config = $config;
        $this->language = $language;
    }

    /**
     * Handle a generic exception.
     *
     * @param \Throwable $e       The exception.
     * @param array      $context Additional context (e.g., update, handler).
     */
    public function handleException(\Throwable $e, array $context = []): void
    {
        $message = $e->getMessage();
        $trace = $e->getTraceAsString();
        $this->logger->error($message, array_merge($context, ['trace' => $trace]));

        $this->dispatcher->dispatch(Events::ERROR_OCCURRED, $e, $context);

        // If the exception is a user-friendly one, send a message to the user.
        if ($e instanceof UserFriendlyException) {
            $this->sendUserError($e, $context);
        }
    }

    /**
     * Handle a middleware error.
     *
     * @param \Throwable $e              The exception.
     * @param array      $update         The update that caused the error.
     * @param string     $middlewareClass The middleware class name.
     */
    public function handleMiddlewareError(\Throwable $e, array $update, string $middlewareClass): void
    {
        $this->dispatcher->dispatch(Events::MIDDLEWARE_FAILED, $e, $middlewareClass, $update);
        $this->handleException($e, ['middleware' => $middlewareClass, 'update' => $update]);
    }

    /**
     * Send a user‑friendly error message.
     *
     * @param UserFriendlyException $e       The user‑friendly exception.
     * @param array                 $context Additional context.
     */
    private function sendUserError(UserFriendlyException $e, array $context = []): void
    {
        $update = $context['update'] ?? [];
        $fromId = $this->extractFromIdIfExists($update);
        if ($fromId === null) {
            return;
        }

        $lang = $this->language->getDefaultLanguage(); // Could be user-specific if we have user repository.
        // Try to get user language from context if available.
        if (isset($context['user_id'])) {
            // We could inject UserRepositoryInterface here, but to keep it simple, use default.
        }

        $message = $this->language->get($e->getUserMessageKey() ?? 'error', $lang);
        $this->client->sendMessage($fromId, $message);
        $this->logger->debug('Sent user‑friendly error message', ['user_id' => $fromId, 'key' => $e->getUserMessageKey()]);
    }
}

/**
 * Exception that should be shown to the user with a friendly message.
 */
class UserFriendlyException extends \Exception
{
    private string $userMessageKey;

    /**
     * Constructor.
     *
     * @param string          $userMessageKey The translation key for the user message.
     * @param string          $message        System message (logged).
     * @param int             $code           Exception code.
     * @param \Throwable|null $previous       Previous exception.
     */
    public function __construct(string $userMessageKey, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->userMessageKey = $userMessageKey;
    }

    /**
     * Get the translation key for the user message.
     *
     * @return string
     */
    public function getUserMessageKey(): string
    {
        return $this->userMessageKey;
    }
}
