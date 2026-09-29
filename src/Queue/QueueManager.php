<?php
namespace Reactor\Queue;

use Reactor\Contracts\QueueManagerInterface;
use Reactor\Core\Container;

/**
 * High-level queue manager that resolves job instances from the container
 * and dispatches their payload to the handler method.
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

    public function process(string $queue = 'default', int $maxJobs = 10): void
    {
        for ($i = 0; $i < $maxJobs; $i++) {
            $job = $this->driver->pop($queue);
            if (!$job) {
                break;
            }

            try {
                /** @var \Reactor\Contracts\JobInterface $jobInstance */
                $jobInstance = $this->container->get($job['payload']['job']);
                $jobInstance->handle($job['payload']['data'] ?? []);
                $this->driver->delete($job);
            } catch (\Throwable $e) {
                $this->driver->release($job, 60);
            }
        }
    }
}
