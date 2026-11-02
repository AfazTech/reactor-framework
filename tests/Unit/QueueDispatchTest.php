<?php

namespace Tests\Unit;

use Amp\Future;
use PHPUnit\Framework\TestCase;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use Reactor\Contracts\QueueManagerInterface;
use Reactor\Core\Container;
use Reactor\Queue\QueueManager;

class QueueDispatchTest extends TestCase
{
    public function testDispatchDoesNotCompleteBeforeTheInsert(): void
    {
        $inserted = false;
        $db = $this->createMock(AsyncDatabaseManagerInterface::class);
        $db->expects(self::once())->method('execute')->willReturnCallback(function () use (&$inserted) {
            return \Amp\async(function () use (&$inserted) {
                \Amp\delay(0.01);
                $inserted = true;
                return 1;
            });
        });
        $queue = new QueueManager(new Container(), $this->createMock(QueueManagerInterface::class), $db);
        $queue->push('ExampleJob', ['value' => 1])->await();
        self::assertTrue($inserted);
    }

    public function testDispatchPropagatesAnInsertFailure(): void
    {
        $db = $this->createMock(AsyncDatabaseManagerInterface::class);
        $db->method('execute')->willReturn(Future::error(new \RuntimeException('insert failed')));
        $queue = new QueueManager(new Container(), $this->createMock(QueueManagerInterface::class), $db);
        $this->expectExceptionMessage('insert failed');
        $queue->push('ExampleJob')->await();
    }
}
