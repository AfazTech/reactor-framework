<?php
namespace Reactor\Queue;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Container;
use function Amp\async;
use function Amp\delay;

/**
 * Long-running queue worker.
 *
 * Responsibility split:
 *   - QueueManager owns the primitive queue operations (pop/delete/release).
 *   - Worker owns the loop, retry policy, timeout enforcement and
 *     graceful shutdown via the restart signal.
 *   - FailedJobRepository persists jobs that exhausted their retries.
 *
 * Timeout enforcement uses Amp's TimeoutCancellation. Jobs that perform
 * async I/O (the common case in Reactor) are truly cancelled when the
 * timeout fires. Jobs that block the PHP process with synchronous code
 * cannot be interrupted; for those, the worker abandons waiting and
 * continues with the next job, leaving the leaked fiber to finish on
 * its own.
 */
class Worker
{
    public function __construct(
        private QueueManager $manager,
        private Container $container,
        private LoggerInterface $logger,
        private FailedJobRepository $failedJobs,
        private Signals $signals,
    ) {
    }

    /**
     * Run the worker loop and return the number of processed jobs.
     */
    public function work(WorkerOptions $options): int
    {
        $startedAt = time();
        $processed = 0;

        $this->logger->info('Queue worker started', [
            'queues'  => (array) $options->queue,
            'tries'   => $options->tries,
            'timeout' => $options->timeout,
        ]);

        while (true) {
            if ($this->shouldStop($options, $startedAt, $processed)) {
                break;
            }

            $job = $this->manager->getNextJob($options->queue);

            if ($job === null) {
                if ($options->once || $options->stopWhenEmpty) {
                    break;
                }
                delay((float) $options->sleep);
                continue;
            }

            $this->process($job, $options);
            $processed++;

            if ($options->once) {
                break;
            }
        }

        $this->logger->info('Queue worker stopped', ['processed' => $processed]);

        return $processed;
    }

    private function process(array $job, WorkerOptions $options): void
    {
        try {
            $this->execute($job, $options);
            $this->manager->delete($job);
            $this->logger->debug('Job processed', [
                'id'    => $job['id'],
                'class' => $job['payload']['job'] ?? 'unknown',
            ]);
        } catch (CancelledException $e) {
            $this->handleFailure(
                $job,
                new \RuntimeException(
                    'Job exceeded the ' . $options->timeout . ' second timeout.'
                ),
                $options
            );
        } catch (\Throwable $e) {
            $this->handleFailure($job, $e, $options);
        }
    }

    private function execute(array $job, WorkerOptions $options): void
    {
        $class = $job['payload']['job'] ?? null;
        $data = $job['payload']['data'] ?? [];

        if (!is_string($class) || $class === '') {
            throw new \RuntimeException('Job payload is missing the "job" class name.');
        }

        if (!class_exists($class)) {
            throw new \RuntimeException("Job class not found: {$class}");
        }

        $instance = $this->container->get($class);

        if (!method_exists($instance, 'handle')) {
            throw new \RuntimeException("Job {$class} must have a handle() method.");
        }

        $future = async(static fn () => $instance->handle($data));

        if ($options->timeout > 0) {
            $future->await(new TimeoutCancellation((float) $options->timeout));
        } else {
            $future->await();
        }
    }

    private function handleFailure(array $job, \Throwable $e, WorkerOptions $options): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);

        if ($attempts >= $options->tries) {
            $this->failedJobs->log(
                queue: (string) ($job['queue'] ?? 'default'),
                payload: json_encode($job['payload'] ?? []),
                exception: $e,
            );
            $this->manager->delete($job);

            $this->logger->error('Job marked as failed', [
                'id'       => $job['id'],
                'attempts' => $attempts,
                'error'    => $e->getMessage(),
            ]);
            return;
        }

        // Exponential backoff, capped at 60 seconds.
        $delay = (int) min(60, 2 ** $attempts);
        $this->manager->release($job, $delay);

        $this->logger->warning('Job released for retry', [
            'id'       => $job['id'],
            'attempts' => $attempts,
            'delay'    => $delay,
            'error'    => $e->getMessage(),
        ]);
    }

    private function shouldStop(WorkerOptions $options, int $startedAt, int $processed): bool
    {
        if ($options->maxJobs > 0 && $processed >= $options->maxJobs) {
            return true;
        }

        if ($options->maxTime > 0 && (time() - $startedAt) >= $options->maxTime) {
            return true;
        }

        if ($options->memory > 0) {
            $usedMb = (int) (memory_get_usage(true) / 1048576);
            if ($usedMb >= $options->memory) {
                $this->logger->warning('Worker memory limit reached', [
                    'used_mb'  => $usedMb,
                    'limit_mb' => $options->memory,
                ]);
                return true;
            }
        }

        if ($this->signals->shouldRestart($startedAt)) {
            return true;
        }

        return false;
    }
}
