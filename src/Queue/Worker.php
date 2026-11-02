<?php
namespace Reactor\Queue;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\NullCancellation;
use Reactor\Contracts\CancellableJobInterface;
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
 * TimeoutCancellation is passed to jobs implementing CancellableJobInterface.
 * Cancellation is cooperative: a job must pass it to its I/O operations.
 * A timed-out attempt is always awaited until it actually settles before
 * releasing its reservation. Legacy jobs cannot be forcibly cancelled; if
 * they finish after the deadline they are acknowledged, not retried.
 * Use process supervision for a hard limit on blocking or uncooperative jobs.
 *
 * Graceful shutdown: SIGTERM and SIGINT set a stop flag. The worker
 * finishes the current job, then exits. Without this handler a plain
 * `kill <pid>` would terminate the process mid-job, leaving a reserved
 * row that no other worker can reclaim until retry_after elapses. The
 * signal handler only works when ext-pcntl is available; on Windows
 * the flag is simply never set and the supervisor handles termination.
 */
class Worker
{
    private bool $stopRequested = false;

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

        $this->installSignalHandlers();

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

    /**
     * Install pcntl signal handlers that request a graceful shutdown.
     *
     * SIGTERM and SIGINT both flip the internal stop flag. The loop
     * reads it in shouldStop() between jobs, so the job currently
     * running is completed and its row is deleted or released before
     * the process exits. No-op when ext-pcntl is not available.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            $this->stopRequested = true;
            $this->logger->info('Graceful shutdown requested', ['signal' => $signal]);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
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

        $cancellation = $options->timeout > 0
            ? new TimeoutCancellation((float) $options->timeout)
            : null;
        $future = async(static function () use ($instance, $data, $cancellation): void {
            if ($instance instanceof CancellableJobInterface) {
                $instance->handle($data, $cancellation ?? new NullCancellation());
            } else {
                $instance->handle($data);
            }
        });

        try {
            $future->await($cancellation);
        } catch (CancelledException $e) {
            if ($cancellation === null || !$cancellation->isRequested()) {
                throw $e;
            }

            // await(cancellation) only cancels the wait. Do not release or
            // retry the reservation while the original job can still run.
            // If it really was cancelled, await() propagates that failure.
            $future->await();
            $this->logger->warning('Job finished after its timeout; acknowledging without retry', [
                'id' => $job['id'],
                'timeout' => $options->timeout,
            ]);
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
        if ($this->stopRequested) {
            return true;
        }

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
