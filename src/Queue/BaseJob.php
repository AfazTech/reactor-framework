<?php
namespace Reactor\Queue;

use Reactor\Contracts\JobInterface;
use Neili\Client;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use App\Contracts\Repository\UserRepositoryInterface;

abstract class BaseJob implements JobInterface
{
    protected Client $client;
    protected LoggerInterface $logger;
    protected LanguageInterface $language;
    protected UserRepositoryInterface $userRepository;

    public function __construct(
        Client $client,
        LoggerInterface $logger,
        LanguageInterface $language,
        UserRepositoryInterface $userRepository
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->language = $language;
        $this->userRepository = $userRepository;
    }
}
