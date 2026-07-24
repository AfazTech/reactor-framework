<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\MiddlewareManager;
use Reactor\Core\Container;
use Reactor\Core\ErrorHandler;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\MiddlewareInterface;
use Reactor\Enums\MiddlewareMode;
use PHPUnit\Framework\MockObject\MockObject;

class MiddlewareManagerTest extends TestCase
{
    private Container&MockObject $container;
    private ErrorHandler&MockObject $errorHandler;
    private LoggerInterface&MockObject $logger;
    private MiddlewareManager $manager;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->errorHandler = $this->createMock(ErrorHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->manager = new MiddlewareManager(
            $this->container,
            $this->errorHandler,
            $this->logger
        );
    }

    private function createMiddlewareMock(string $class, int $priority, MiddlewareMode $mode, array $groups = [], array $allowed = ['any']): array
    {
        return [
            'class' => $class,
            'priority' => $priority,
            'mode' => $mode,
            'groups' => $groups,
            'allowedUpdates' => $allowed,
        ];
    }

    /** @test */
    public function it_executes_global_middleware_in_priority_order()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';

        $middleware1 = $this->createMock(MiddlewareInterface::class);
        $middleware1->expects($this->once())->method('handle')->willReturn(false);

        $middleware2 = $this->createMock(MiddlewareInterface::class);
        $middleware2->expects($this->once())->method('handle')->willReturn(false);

        $this->container
            ->method('get')
            ->willReturnMap([
                ['MiddlewareA', $middleware1],
                ['MiddlewareB', $middleware2],
            ]);

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('MiddlewareA', 50, MiddlewareMode::GLOBAL),
            $this->createMiddlewareMock('MiddlewareB', 100, MiddlewareMode::GLOBAL),
        ]);

        $result = $this->manager->executeGlobal($update, $updateType);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_stops_execution_when_global_middleware_returns_true()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects($this->once())->method('handle')->willReturn(true);

        $this->container->method('get')->willReturn($middleware);

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('MiddlewareA', 100, MiddlewareMode::GLOBAL),
        ]);

        $result = $this->manager->executeGlobal($update, $updateType);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_only_executes_global_middleware_for_allowed_update_types()
    {
        $update = ['callback_query' => ['data' => 'test']];
        $updateType = 'callback_query';

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects($this->never())->method('handle');

        $this->container->method('get')->willReturn($middleware);

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('MiddlewareA', 100, MiddlewareMode::GLOBAL, [], ['message']),
        ]);

        $result = $this->manager->executeGlobal($update, $updateType);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_executes_group_middleware_based_on_handler_groups()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $handlerGroups = ['admin', 'moderator'];

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects($this->once())->method('handle')->willReturn(false);

        $this->container->method('get')->willReturn($middleware);

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('GroupMiddleware', 50, MiddlewareMode::GROUP, ['admin', 'moderator']),
        ]);

        $result = $this->manager->executeGroup($update, $updateType, $handlerGroups);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_skips_group_middleware_when_no_groups_match()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $handlerGroups = ['guest'];

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects($this->never())->method('handle');

        $this->container->method('get')->willReturn($middleware);

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('GroupMiddleware', 50, MiddlewareMode::GROUP, ['admin']),
        ]);

        $result = $this->manager->executeGroup($update, $updateType, $handlerGroups);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_executes_local_middleware_explicitly_listed()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $handlerUseMiddlewares = ['MiddlewareA'];

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects($this->once())->method('handle')->willReturn(false);

        $this->container->method('get')->willReturn($middleware);

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('MiddlewareA', 50, MiddlewareMode::LOCAL),
        ]);

        $result = $this->manager->executeLocal($update, $updateType, $handlerUseMiddlewares);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_skips_local_middleware_not_in_use_list()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $handlerUseMiddlewares = ['AnotherMiddleware'];

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects($this->never())->method('handle');

        $this->container->method('get')->willReturn($middleware);

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('MiddlewareA', 50, MiddlewareMode::LOCAL),
        ]);

        $result = $this->manager->executeLocal($update, $updateType, $handlerUseMiddlewares);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_handles_exceptions_in_middleware_by_stopping_processing()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('Test exception'));

        $this->container->method('get')->willReturn($middleware);
        $this->errorHandler
            ->expects($this->once())
            ->method('handleMiddlewareError');

        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('MiddlewareA', 100, MiddlewareMode::GLOBAL),
        ]);

        $result = $this->manager->executeGlobal($update, $updateType);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_does_not_execute_same_middleware_twice_in_single_execution()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';

        $middleware = $this->createMock(MiddlewareInterface::class);
        // In a single executeMiddlewares call, duplicate prevention should work
        $middleware->expects($this->once())->method('handle')->willReturn(false);

        $this->container->method('get')->willReturn($middleware);

        // Register the same middleware twice in global list (should only run once)
        $this->manager->setMiddlewares([
            $this->createMiddlewareMock('MiddlewareA', 50, MiddlewareMode::GLOBAL),
            $this->createMiddlewareMock('MiddlewareA', 40, MiddlewareMode::GLOBAL), // duplicate
        ]);

        $result = $this->manager->executeGlobal($update, $updateType);
        $this->assertFalse($result);
    }
}
