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
     * Resolve a job instance from the container.
     */
    public function resolve(string $class): object
    {
        return $this->container->get($class);
    }

    /**
     * Process up to $maxJobs jobs from the given queue.
     *
     * @deprecated Use Reactor\Queue\Worker::work() instead. Kept for
     *             backward compatibility with older applications.
     */
    public function process(string $queue = 'default', int $maxJobs = 10): void
    {
        for ($i = 0; $i < $maxJobs; $i++) {
            $job = $this->driver->pop($queue);
            if (!$job) {
                break;
            }

            try {
                $jobInstance = $this->container->get($job['payload']['job']);
                $jobInstance->handle($job['payload']['data'] ?? []);
                $this->driver->delete($job);
            } catch (\Throwable $e) {
                $this->driver->release($job, 60);
            }
        }
    }
}
