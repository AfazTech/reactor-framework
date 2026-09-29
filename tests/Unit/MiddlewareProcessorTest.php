<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Processing\MiddlewareProcessor;
use Reactor\Core\MiddlewareManager;
use Reactor\Contracts\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class MiddlewareProcessorTest extends TestCase
{
    private MiddlewareManager&MockObject $manager;
    private LoggerInterface&MockObject $logger;
    private MiddlewareProcessor $processor;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(MiddlewareManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->processor = new MiddlewareProcessor($this->manager, $this->logger);
    }

    /** @test */
    public function it_processes_middleware_in_correct_order_global_group_local()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $metadata = [
            'groups' => ['admin'],
            'useMiddlewares' => ['LocalMiddleware'],
        ];

        // Each method should be called exactly once
        $this->manager
            ->expects($this->once())
            ->method('executeGlobal')
            ->with($update, $updateType)
            ->willReturn(false);

        $this->manager
            ->expects($this->once())
            ->method('executeGroup')
            ->with($update, $updateType, ['admin'])
            ->willReturn(false);

        $this->manager
            ->expects($this->once())
            ->method('executeLocal')
            ->with($update, $updateType, ['LocalMiddleware'])
            ->willReturn(false);

        $result = $this->processor->process($update, $updateType, $metadata);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_stops_at_global_middleware_if_it_halts()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $metadata = ['groups' => [], 'useMiddlewares' => []];

        $this->manager
            ->expects($this->once())
            ->method('executeGlobal')
            ->willReturn(true);

        $this->manager
            ->expects($this->never())
            ->method('executeGroup');

        $this->manager
            ->expects($this->never())
            ->method('executeLocal');

        $result = $this->processor->process($update, $updateType, $metadata);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_stops_at_group_middleware_if_it_halts()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $metadata = ['groups' => ['admin'], 'useMiddlewares' => []];

        $this->manager
            ->expects($this->once())
            ->method('executeGlobal')
            ->willReturn(false);

        $this->manager
            ->expects($this->once())
            ->method('executeGroup')
            ->willReturn(true);

        $this->manager
            ->expects($this->never())
            ->method('executeLocal');

        $result = $this->processor->process($update, $updateType, $metadata);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_stops_at_local_middleware_if_it_halts()
    {
        $update = ['message' => ['text' => 'hi']];
        $updateType = 'message';
        $metadata = [
            'groups' => [],
            'useMiddlewares' => ['LocalMiddleware'],
        ];

        $this->manager
            ->expects($this->once())
            ->method('executeGlobal')
            ->willReturn(false);

        $this->manager
            ->expects($this->once())
            ->method('executeGroup')
            ->willReturn(false);

        $this->manager
            ->expects($this->once())
            ->method('executeLocal')
            ->willReturn(true);

        $result = $this->processor->process($update, $updateType, $metadata);
        $this->assertTrue($result);
    }
}
