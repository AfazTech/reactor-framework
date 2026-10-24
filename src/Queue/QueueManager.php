<?php
namespace Reactor\Queue;

use Reactor\Contracts\QueueManagerInterface;
use Reactor\Core\Container;

/**
 * High-level queue manager that resolves job instances from the container
 * and dispatches their payload to the handler method.
 *
 * The heavy lifting for long-running workers lives in Reactor\Queue\Worker.
 * This class only exposes the primitive queue operations plus a convenience
 * resolve() helper.
 */
class QueueManager
{
    private Container $container;
    private QueueManagerInterface $driver;

    public function __construct(Container $container, QueueManagerInterface $driver)
    {
        $this->container = $container;
        $this->driver = $driver;
    }

    public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void
    {
        $this->driver->push($jobClass, $data, $queue, $delay);
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
     *
     * Returns 0 when the active driver does not support retry_after
     * (any driver other than DatabaseQueue).
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
     *
     * Returns 0 when the driver does not use a time-based reservation
     * window (any driver other than DatabaseQueue).
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
