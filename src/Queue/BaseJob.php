<?php
namespace Reactor\Queue;

use Reactor\Contracts\JobInterface;
use Neili\Client;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;

/**
 * Base class for queue jobs.
 *
 * Provides the common dependencies (Telegram client, logger, language
 * manager and a framework-level user provider) that most jobs need.
 * Application-specific repositories should be injected by the concrete
 * job itself, keeping the framework decoupled from app namespaces.
 */
abstract class BaseJob implements JobInterface
{
    protected Client $client;
    protected LoggerInterface $logger;
    protected LanguageInterface $language;
    protected UserProviderInterface $userProvider;

    public function __construct(
        Client $client,
        LoggerInterface $logger,
        LanguageInterface $language,
        UserProviderInterface $userProvider
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->language = $language;
        $this->userProvider = $userProvider;
    }
}
