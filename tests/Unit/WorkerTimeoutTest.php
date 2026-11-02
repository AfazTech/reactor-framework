<?php

namespace Tests\Unit;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Reactor\Contracts\CancellableJobInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Container;
use Reactor\Queue\FailedJobRepository;
use Reactor\Queue\QueueManager;
use Reactor\Queue\Signals;
use Reactor\Queue\Worker;
use Reactor\Queue\WorkerOptions;

class WorkerTimeoutTest extends TestCase
{
    public function testLegacyJobFinishesBeforeItIsAcknowledgedAndIsNotRetried(): void
    {
        $job = new class {
            public bool $finished = false;
            public function handle(array $data): void
            {
                \Amp\delay(1.1);
                $this->finished = true;
            }
        };
        $container = new Container();
        $container->singleton(get_class($job), fn () => $job);
        $payload = ['id' => 1, 'attempts' => 1, 'payload' => ['job' => get_class($job)]];
        $manager = $this->createMock(QueueManager::class);
        $manager->method('getNextJob')->willReturn($payload);
        $manager->expects(self::never())->method('release');
        $manager->expects(self::once())->method('delete')->willReturnCallback(function () use ($job) {
            self::assertTrue($job->finished, 'Reservation must remain held until the job finishes.');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Job finished after its timeout; acknowledging without retry', self::anything()
        );
        $worker = new Worker($manager, $container, $logger,
            $this->createMock(FailedJobRepository::class), $this->createMock(Signals::class));
        self::assertSame(1, $worker->work(new WorkerOptions(timeout: 1, once: true)));
        self::assertTrue($job->finished);
    }

    public function testCooperativeCancellationSettlesTheJobBeforeRelease(): void
    {
        $job = new class implements CancellableJobInterface {
            public bool $settled = false;
            public bool $sideEffect = false;
            public function handle(array $data, ?Cancellation $cancellation = null): void
            {
                try {
                    \Amp\delay(10, cancellation: $cancellation);
                    $this->sideEffect = true;
                } finally {
                    $this->settled = true;
                }
            }
        };
        $container = new Container();
        $container->singleton(get_class($job), fn () => $job);
        $manager = $this->createMock(QueueManager::class);
        $manager->method('getNextJob')->willReturn([
            'id' => 2, 'attempts' => 1, 'payload' => ['job' => get_class($job)],
        ]);
        $manager->expects(self::never())->method('delete');
        $manager->expects(self::once())->method('release')->willReturnCallback(function () use ($job) {
            self::assertTrue($job->settled);
            self::assertFalse($job->sideEffect);
        });
        $worker = new Worker($manager, $container, $this->createMock(LoggerInterface::class),
            $this->createMock(FailedJobRepository::class), $this->createMock(Signals::class));
        self::assertSame(1, $worker->work(new WorkerOptions(timeout: 1, once: true)));
    }
}
