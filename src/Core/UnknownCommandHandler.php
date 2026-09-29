<?php

namespace Reactor\Core;

use Neili\Client;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\Traits\FromIdExtractor;
use Reactor\Contracts\LoggerInterface;

/**
 * Built-in default fallback handler.
 *
 * This is the framework-level safety net that runs when *no* handler
 * matched an incoming update, including the case where the application
 * has not registered any #[Fallback] handler.
 *
 * Precedence:
 *   1. Any handler carrying the #[Fallback] attribute (highest priority wins).
 *   2. This class, invoked automatically by UpdateProcessor.
 *
 * Applications that want a custom reply for unmatched updates should
 * register a #[Fallback] handler; the framework will then delegate to
 * that class instead of this built-in default.
 */
class UnknownCommandHandler
{
    use FromIdExtractor;

    private Client $client;
    private LanguageInterface $language;
    private UserProviderInterface $userProvider;
    private LoggerInterface $logger;

    public function __construct(
        Client $client,
        LanguageInterface $language,
        UserProviderInterface $userProvider,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->language = $language;
        $this->userProvider = $userProvider;
        $this->logger = $logger;
    }

    public function handle(array $update): void
    {
        $fromId = $this->extractFromIdIfExists($update);
        if ($fromId === null) {
            $this->logger->debug('Cannot send unknown command: no from_id found');
            return;
        }

        $lang = $this->userProvider->getLanguage($fromId) ?? $this->language->getDefaultLanguage();
        $message = $this->language->get('unknown_command', $lang);

        $this->client->sendMessage($fromId, $message);
        $this->logger->debug('Sent unknown command message', ['user_id' => $fromId]);
    }
}
