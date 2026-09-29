<?php

namespace Reactor\Core;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;
use Reactor\Events;
use Neili\Client;
use Reactor\Core\Config;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\Traits\FromIdExtractor;
use Reactor\Exceptions\UserFriendlyException;

/**
 * Centralised error handler for the application.
 *
 * Distinguishes between system errors (logged, possibly alerted) and
 * user-friendly errors (which also send a message to the user).
 *
 * User-facing messages are resolved using the recipient's language,
 * matching the behavior of UnknownCommandHandler. When the user's
 * language cannot be determined (no user id, provider failure, or
 * provider returns an empty value) the language manager's default
 * language is used as a fallback.
 */
class ErrorHandler
{
    use FromIdExtractor;

    private LoggerInterface $logger;
    private EventDispatcher $dispatcher;
    private Client $client;
    private Config $config;
    private LanguageInterface $language;
    private UserProviderInterface $userProvider;

    public function __construct(
        LoggerInterface $logger,
        EventDispatcher $dispatcher,
        Client $client,
        Config $config,
        LanguageInterface $language,
        UserProviderInterface $userProvider
    ) {
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->client = $client;
        $this->config = $config;
        $this->language = $language;
        $this->userProvider = $userProvider;
    }

    /**
     * Handle a generic exception.
     */
    public function handleException(\Throwable $e, array $context = []): void
    {
        $message = $e->getMessage();
        $trace = $e->getTraceAsString();
        $this->logger->error($message, array_merge($context, ['trace' => $trace]));

        $this->dispatcher->dispatch(Events::ERROR_OCCURRED, $e, $context);

        if ($e instanceof UserFriendlyException) {
            $this->sendUserError($e, $context);
        }
    }

    /**
     * Handle a middleware error.
     */
    public function handleMiddlewareError(\Throwable $e, array $update, string $middlewareClass): void
    {
        $this->dispatcher->dispatch(Events::MIDDLEWARE_FAILED, $e, $middlewareClass, $update);
        $this->handleException($e, ['middleware' => $middlewareClass, 'update' => $update]);
    }

    /**
     * Send a user-friendly error message in the recipient's language.
     */
    private function sendUserError(UserFriendlyException $e, array $context = []): void
    {
        $update = $context['update'] ?? [];
        $fromId = $this->extractFromIdIfExists($update);
        if ($fromId === null) {
            return;
        }

        $lang = $this->resolveUserLanguage($fromId);
        $message = $this->language->get($e->getUserMessageKey() ?? 'error', $lang);
        $this->client->sendMessage($fromId, $message);

        $this->logger->debug('Sent user-friendly error message', [
            'user_id'  => $fromId,
            'language' => $lang,
            'key'      => $e->getUserMessageKey(),
        ]);
    }

    /**
     * Resolve the recipient's language, falling back to the default.
     *
     * The provider itself may throw (e.g. database unavailable). In that
     * case we log at debug level and fall back to the default language
     * rather than letting the error path crash while reporting an error.
     */
    private function resolveUserLanguage(int $fromId): string
    {
        try {
            $lang = $this->userProvider->getLanguage($fromId);
            if (is_string($lang) && $lang !== '') {
                return $lang;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to resolve user language, using default', [
                'user_id' => $fromId,
                'error'   => $e->getMessage(),
            ]);
        }

        return $this->language->getDefaultLanguage();
    }
}
