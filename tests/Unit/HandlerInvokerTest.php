<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Container;
use Reactor\Core\HandlerExecution;
use Reactor\Core\ErrorHandler;
use Reactor\Core\Processing\HandlerInvoker;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\EventDispatcher;
use Neili\Client;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\UserProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Amp\Future;

class HandlerInvokerTest extends TestCase
{
    private Container&MockObject $container;
    private ErrorHandler&MockObject $errorHandler;
    private LoggerInterface&MockObject $logger;
    private EventDispatcher&MockObject $dispatcher;
    private Client $client;
    private LanguageInterface&MockObject $language;
    private UserProviderInterface&MockObject $userProvider;
    private HandlerInvoker $invoker;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->errorHandler = $this->createMock(ErrorHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcher::class);

        // Client is final, so we use getMockBuilder with disableOriginalConstructor.
        $this->client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->disableAutoReturnValueGeneration()
            ->getMock();

        $this->language = $this->createMock(LanguageInterface::class);
        $this->userProvider = $this->createMock(UserProviderInterface::class);

        $this->invoker = new HandlerInvoker(
            $this->container,
            $this->errorHandler,
            $this->logger,
            $this->dispatcher,
            $this->client,
            $this->language,
            $this->userProvider
        );
    }

    /** @test */
    public function it_invokes_handler_successfully()
    {
        $update = ['message' => ['text' => 'hi']];
        $params = ['test' => 'value'];
        $handlerClass = 'App\\Handlers\\TestHandler';

        $handler = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['execute'])
            ->getMock();
        $handler->expects($this->once())
            ->method('execute')
            ->with($update, $params);

        $this->container
            ->method('get')
            ->with($handlerClass)
            ->willReturn($handler);

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with('handler.executed', $handlerClass, $update);

        $execution = new HandlerExecution(
            handlerClass: $handlerClass,
            params: $params,
            update: $update
        );

        $this->invoker->invoke($execution);
    }

    /** @test */
    public function it_handles_exception_during_handler_execution()
    {
        $update = ['message' => ['from' => ['id' => 123]]];
        $handlerClass = 'App\\Handlers\\TestHandler';

        $handler = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['execute'])
            ->getMock();
        $handler->expects($this->once())
            ->method('execute')
            ->willThrowException(new \RuntimeException('Test error'));

        $this->container->method('get')->willReturn($handler);

        $this->language
            ->method('getDefaultLanguage')
            ->willReturn('en');

        $this->userProvider
            ->method('getLanguage')
            ->with(123)
            ->willReturn('en');

        $this->language
            ->method('get')
            ->with('error', 'en')
            ->willReturn('An error occurred');

        // sendMessage() returns Amp\Future, which is final and cannot be
        // mocked, so a real completed Future is supplied instead.
        $this->client
            ->expects($this->once())
            ->method('sendMessage')
            ->with(123, 'An error occurred')
            ->willReturn(Future::complete(null));

        $this->errorHandler
            ->expects($this->once())
            ->method('handleException');

        $execution = new HandlerExecution(
            handlerClass: $handlerClass,
            update: $update
        );

        $this->invoker->invoke($execution);
    }

    /** @test */
    public function it_does_not_send_error_message_if_no_from_id()
    {
        $update = ['message' => ['text' => 'hi']];
        $handlerClass = 'App\\Handlers\\TestHandler';

        $handler = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['execute'])
            ->getMock();
        $handler->method('execute')->willThrowException(new \RuntimeException('Test error'));

        $this->container->method('get')->willReturn($handler);
        $this->errorHandler->method('handleException');

        $this->client
            ->expects($this->never())
            ->method('sendMessage');

        $execution = new HandlerExecution(
            handlerClass: $handlerClass,
            update: $update
        );

        $this->invoker->invoke($execution);
    }

    /** @test */
    public function it_throws_exception_if_handler_does_not_have_execute_method()
    {
        $update = ['message' => ['text' => 'hi']];
        $handlerClass = 'App\\Handlers\\TestHandler';

        $handler = $this->createMock(\stdClass::class);

        $this->container->method('get')->willReturn($handler);

        // The missing execute() check now runs before the try block, so it
        // propagates as a RuntimeException instead of being swallowed.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Handler {$handlerClass} must have an execute() method.");

        $execution = new HandlerExecution(
            handlerClass: $handlerClass,
            update: $update
        );

        $this->invoker->invoke($execution);
    }
}
