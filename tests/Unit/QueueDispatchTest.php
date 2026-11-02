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
    public function testDispatchUsesTheConfiguredTableWithRealSqlite(): void
    {
        $db = new \Reactor\Database\Async\AsyncSqliteManager(['database' => ':memory:']);
        $db->execute('CREATE TABLE pending_jobs (queue TEXT, payload TEXT, attempts INTEGER,
            reserved_at INTEGER, available_at INTEGER, created_at INTEGER)')->await();
        $queue = new QueueManager(new Container(), $this->createMock(QueueManagerInterface::class), $db, 'pending_jobs');
        $queue->push('ExampleJob', ['value' => 42], 'emails')->await();
        $job = $db->fetchOne('SELECT * FROM pending_jobs')->await();
        self::assertSame('emails', $job['queue']);
        self::assertSame(['job' => 'ExampleJob', 'data' => ['value' => 42]], json_decode($job['payload'], true));
        self::assertSame(0, $job['attempts']);
    }

    public function testQueueTableRejectsSqlFragments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QueueManager(new Container(), $this->createMock(QueueManagerInterface::class),
            $this->createMock(AsyncDatabaseManagerInterface::class), 'jobs; DROP TABLE users');
    }

}
