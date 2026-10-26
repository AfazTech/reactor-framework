<?php

namespace Reactor\Queue;

use Amp\Future;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use Reactor\Contracts\QueueManagerInterface;
use Reactor\Core\Container;
use function Amp\async;

/**
 * High-level queue manager that resolves job instances from the container
 * and dispatches their payload to the handler method.
 *
 * push() uses AsyncDatabaseManagerInterface so that dispatching a job
 * from inside a handler never blocks the event loop. The remaining
 * operations (pop/release/delete/size) are still provided by the
 * synchronous QueueManagerInterface driver; they are only used by the
 * queue worker, which runs in a separate CLI process where blocking is
 * acceptable.
 */
class QueueManager
{
    private Container $container;
    private QueueManagerInterface $driver;
    private AsyncDatabaseManagerInterface $asyncDb;

    public function __construct(
        Container $container,
        QueueManagerInterface $driver,
        AsyncDatabaseManagerInterface $asyncDb,
    ) {
        $this->container = $container;
        $this->driver = $driver;
        $this->asyncDb = $asyncDb;
    }

    /**
     * Dispatch a job onto the queue without blocking the event loop.
     */
    public function push(
        string $jobClass,
        array $data = [],
        string $queue = 'default',
        int $delay = 0
    ): Future {
        return async(function () use ($jobClass, $data, $queue, $delay): void {
            $payload = json_encode(['job' => $jobClass, 'data' => $data]);
            $now = time();

            $this->asyncDb->execute(
                'INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at)
                 VALUES (:queue, :payload, 0, NULL, :available_at, :created_at)',
                [
                    'queue'        => $queue,
                    'payload'      => $payload,
                    'available_at' => $now + $delay,
                    'created_at'   => $now,
                ]
            );
        });
    }

    /**
     * Pop the next available job, honouring queue priority.
     *
     * @param string|array<int, string> $queues
     */
    public function getNextJob(string|array $queues = 'default'): ?array
    {
        return $this->driver->pop($queues);
    }

    public function release(array $job, int $delay = 0): void
    {
        $this->driver->release($job, $delay);
    }

    public function delete(array $job): void
    {
        $this->driver->delete($job);
    }

    public function size(string $queue): int
    {
        return $this->driver->size($queue);
    }

    /**
     * Count jobs in the queue whose reservation has exceeded retry_after.
     */
    public function staleCount(string $queue): int
    {
        if ($this->driver instanceof DatabaseQueue) {
            return $this->driver->staleCount($queue);
        }

        return 0;
    }

    /**
     * Get the retry_after window used by the active driver.
     */
    public function retryAfter(): int
    {
        if ($this->driver instanceof DatabaseQueue) {
            return $this->driver->getRetryAfter();
        }

        return 0;
    }

    /**
     * Resolve a job instance from the container.
     */
    public function resolve(string $class): object
    {
        return $this->container->get($class);
    }
}
