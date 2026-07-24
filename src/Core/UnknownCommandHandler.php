<?php

namespace Reactor\Core;

use Neili\Client;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\Traits\FromIdExtractor;
use Reactor\Contracts\LoggerInterface;

/**
 * Handles the case where no matching handler is found for an update.
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
