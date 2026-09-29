<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\ErrorHandler;
use Reactor\Core\EventDispatcher;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Core\Config;
use Reactor\Exceptions\UserFriendlyException;
use Neili\Client;
use PHPUnit\Framework\MockObject\MockObject;
use Amp\Future;

/**
 * Verifies that UserFriendlyException messages are sent in the recipient's
 * language, not the configured default, and that the default is used as a
 * fallback when the user's language cannot be determined.
 */
class ErrorHandlerLanguageTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private EventDispatcher&MockObject $dispatcher;
    private Client $client;
    private Config&MockObject $config;
    private LanguageInterface&MockObject $language;
    private UserProviderInterface&MockObject $userProvider;
    private ErrorHandler $handler;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcher::class);
        $this->config = $this->createMock(Config::class);
        $this->language = $this->createMock(LanguageInterface::class);
        $this->userProvider = $this->createMock(UserProviderInterface::class);

        $this->client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->disableAutoReturnValueGeneration()
            ->getMock();

        $this->handler = new ErrorHandler(
            $this->logger,
            $this->dispatcher,
            $this->client,
            $this->config,
            $this->language,
            $this->userProvider
        );
    }

    /** @test */
    public function it_sends_user_friendly_errors_in_the_users_language(): void
    {
        $update = ['message' => ['from' => ['id' => 42]]];

        $this->userProvider
            ->expects($this->once())
            ->method('getLanguage')
            ->with(42)
            ->willReturn('fa');

        $this->language
            ->expects($this->once())
            ->method('get')
            ->with('error', 'fa')
            ->willReturn('خطایی رخ داد');

        $this->client
            ->expects($this->once())
            ->method('sendMessage')
            ->with(42, 'خطایی رخ داد')
            ->willReturn(Future::complete(null));

        $exception = new UserFriendlyException('error');
        $this->handler->handleException($exception, ['update' => $update]);
    }

    /** @test */
    public function it_falls_back_to_default_language_when_user_has_no_preference(): void
    {
        $update = ['message' => ['from' => ['id' => 7]]];

        $this->userProvider
            ->method('getLanguage')
            ->willReturn('');

        $this->language
            ->method('getDefaultLanguage')
            ->willReturn('en');

        $this->language
            ->expects($this->once())
            ->method('get')
            ->with('error', 'en')
            ->willReturn('An error occurred');

        $this->client
            ->expects($this->once())
            ->method('sendMessage')
            ->with(7, 'An error occurred')
            ->willReturn(Future::complete(null));

        $exception = new UserFriendlyException('error');
        $this->handler->handleException($exception, ['update' => $update]);
    }

    /** @test */
    public function it_falls_back_to_default_language_when_provider_throws(): void
    {
        $update = ['message' => ['from' => ['id' => 9]]];

        $this->userProvider
            ->method('getLanguage')
            ->willThrowException(new \RuntimeException('provider down'));

        $this->language
            ->method('getDefaultLanguage')
            ->willReturn('en');

        $this->language
            ->expects($this->once())
            ->method('get')
            ->with('error', 'en')
            ->willReturn('An error occurred');

        $this->client
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(Future::complete(null));

        $exception = new UserFriendlyException('error');
        $this->handler->handleException($exception, ['update' => $update]);
    }

    /** @test */
    public function it_does_not_attempt_to_send_when_no_from_id_is_present(): void
    {
        $this->userProvider->expects($this->never())->method('getLanguage');
        $this->client->expects($this->never())->method('sendMessage');

        $exception = new UserFriendlyException('error');
        $this->handler->handleException($exception, ['update' => []]);
    }
}
