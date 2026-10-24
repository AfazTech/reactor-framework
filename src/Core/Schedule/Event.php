<?php
namespace Reactor\Core\Schedule;

use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\ScheduledJobInterface;
use Reactor\Core\Container;
use Reactor\Core\Traits\ProcessCheck;

/**
 * A scheduled event that can be configured with a cron expression
 * and optional overlap prevention.
 *
 * The lock directory defaults to the host application's storage path
 * (resolved via the storage_path() helper) so the framework never
 * writes into its own package directory inside the Composer vendor
 * tree. A custom directory can be supplied for advanced setups.
 *
 * Overlap prevention is PID-aware:
 *
 *   - The lock file stores the PID of the process that acquired it.
 *   - A subsequent acquireLock() from another process skips execution
 *     as long as that PID is still alive, regardless of how long the
 *     task has been running.
 *   - A lock whose recorded PID is dead (or whose file is empty /
 *     corrupted) is treated as stale and removed.
 *   - releaseLock() only removes the file when the recorded PID matches
 *     the current process, so a slow task that finishes late cannot
 *     delete a newer lock held by a different process.
 *
 * overlapExpiryMinutes is deliberately NOT used to force-release the
 * lock. A long-running task is a legitimate state; force-releasing it
 * would immediately re-introduce the concurrent-execution bug this
 * class is supposed to prevent. The threshold is used only to emit a
 * warning when a task has been running longer than expected.
 */
class Event
{
    use ProcessCheck;

    private string $expression = '* * * * *';
    private ?string $name = null;
    private bool $preventOverlap = false;
    private int $overlapExpiryMinutes = 5;
    private $callback;
    private ?string $jobClass = null;
    private string $lockDirectory;

    /**
     * Set to true after the first warning about a callback that could
     * not be given a stable name. Prevents log spam if the same event
     * is checked multiple times within one process.
     */
    private bool $nameFallbackWarned = false;

    public function __construct($callback = null, ?string $jobClass = null, ?string $lockDirectory = null)
    {
        $this->callback = $callback;
        $this->jobClass = $jobClass;

        // Prefer an explicit directory, then the host application's
        // storage path. Never fall back to __DIR__ because that would
        // write into the framework's own vendor directory.
        $this->lockDirectory = $lockDirectory ?? storage_path('schedule');
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the event identifier used for lock filenames and log lines.
     *
     * Resolution order:
     *   1. Explicit ->name() call.
     *   2. Job class name for ->job() events.
     *   3. Deterministic identifier derived from the callback:
     *        - Closure: "closure_<file>_<startLine>".
     *        - String callable: "func_<normalised-name>".
     *        - Array callable: "method_<class>_<method>".
     *   4. Fallback to "event_<spl_object_hash>" when nothing else is
     *      possible. In that case overlap prevention cannot work
     *      across processes and acquireLock() will emit a warning.
     */
    public function getName(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        if ($this->jobClass !== null) {
            return $this->jobClass;
        }

        $derived = $this->describeCallback($this->callback);
        if ($derived !== null) {
            return $derived;
        }

        return 'event_' . spl_object_hash($this);
    }

    /**
     * Produce a deterministic identifier for a callback, or null when
     * no stable identifier can be derived.
     */
    private function describeCallback($callback): ?string
    {
        if ($callback instanceof \Closure) {
            try {
                $ref = new \ReflectionFunction($callback);
                $file = $ref->getFileName();
                $line = $ref->getStartLine();
                if ($file !== false && $line !== false && $line > 0) {
                    return sprintf(
                        'closure_%s_%d',
                        pathinfo($file, PATHINFO_FILENAME),
                        $line
                    );
                }
            } catch (\Throwable $e) {
                // Fall through to the null return below.
            }
            return null;
        }

        if (is_string($callback) && $callback !== '') {
            return 'func_' . preg_replace('/[^A-Za-z0-9_]/', '_', $callback);
        }

        if (is_array($callback) && count($callback) === 2) {
            $class  = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            $method = (string) $callback[1];
            if ($class !== '' && $method !== '') {
                return 'method_' . preg_replace('/[^A-Za-z0-9_]/', '_', $class . '_' . $method);
            }
        }

        return null;
    }

    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    // Convenience methods
    public function everyMinute(): self { return $this->cron('* * * * *'); }
    public function everyFiveMinutes(): self { return $this->cron('*/5 * * * *'); }
    public function everyTenMinutes(): self { return $this->cron('*/10 * * * *'); }
    public function everyFifteenMinutes(): self { return $this->cron('*/15 * * * *'); }
    public function everyThirtyMinutes(): self { return $this->cron('*/30 * * * *'); }
    public function hourly(): self { return $this->cron('0 * * * *'); }
    public function hourlyAt(int $minute): self { return $this->cron("{$minute} * * * *"); }
    public function daily(): self { return $this->cron('0 0 * * *'); }
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');
        return $this->cron("{$minute} {$hour} * * *");
    }

    /**
     * Enable overlap prevention.
     *
     * $expiryMinutes is a warning threshold, not a force-release
     * deadline. When a task runs longer than this, acquireLock() logs
     * a warning but still refuses to start a second instance. To
     * recover from a genuinely hung task, kill the process
     * (kill -9) so the recorded PID becomes dead and the next run
     * treats the lock as stale.
     *
     * Passing 0 disables the warning entirely.
     */
    public function withoutOverlapping(int $expiryMinutes = 5): self
    {
        $this->preventOverlap = true;
        $this->overlapExpiryMinutes = $expiryMinutes;
        return $this;
    }

    public function isDue(\DateTimeImmutable $time): bool
    {
        return (new CronExpression($this->expression))->isDue($time);
    }

    private function lockFile(): string
    {
        return $this->lockDirectory . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->getName()) . '.lock';
    }

    /**
     * Try to acquire the lock for this event.
     *
     * @return bool True if the caller should proceed; false to skip.
     */
    private function acquireLock(LoggerInterface $logger): bool
    {
        if (!$this->preventOverlap) {
            return true;
        }

        $taskName = $this->getName();

        // If the event has no stable identifier, overlap prevention is
        // impossible across processes. Warn once and let the task run
        // rather than skipping forever.
        if (str_starts_with($taskName, 'event_')) {
            if (!$this->nameFallbackWarned) {
                $logger->warning(
                    "withoutOverlapping() cannot enforce single-instance execution because "
                    . "this event has no stable identifier. Call ->name('...') on the event.",
                    ['task' => $taskName]
                );
                $this->nameFallbackWarned = true;
            }
            return true;
        }

        if (!is_dir($this->lockDirectory)) {
            mkdir($this->lockDirectory, 0755, true);
        }

        $lockFile = $this->lockFile();

        if (file_exists($lockFile)) {
            $raw = trim((string) file_get_contents($lockFile));
            $recordedPid = $raw === '' ? 0 : (int) $raw;

            if ($recordedPid > 0
                && $recordedPid !== getmypid()
                && $this->isProcessRunning($recordedPid)
            ) {
                // Another live process holds the lock. This is a
                // legitimate state even when the task has been running
                // for a long time, so we never force-release here.
                // $overlapExpiryMinutes only controls observability.
                $age = time() - (int) filemtime($lockFile);

                if ($this->overlapExpiryMinutes > 0
                    && $age >= ($this->overlapExpiryMinutes * 60)
                ) {
                    $logger->warning(
                        "Scheduled task is still running beyond overlapExpiryMinutes; "
                        . "not starting a new instance",
                        [
                            'task'        => $taskName,
                            'pid'         => $recordedPid,
                            'age_seconds' => $age,
                            'expiry_min'  => $this->overlapExpiryMinutes,
                        ]
                    );
                } else {
                    $logger->debug(
                        "Scheduled task skipped: another instance is running",
                        ['task' => $taskName, 'pid' => $recordedPid]
                    );
                }

                return false;
            }

            // Stale lock: process dead, empty file, or corrupted PID.
            $logger->debug(
                "Removing stale scheduled-task lock",
                ['task' => $taskName, 'stale_pid' => $recordedPid]
            );
            @unlink($lockFile);
        }

        file_put_contents($lockFile, (string) getmypid());
        return true;
    }

    /**
     * Release the lock only if this process actually owns it.
     */
    private function releaseLock(): void
    {
        if (!$this->preventOverlap) {
            return;
        }

        if (str_starts_with($this->getName(), 'event_')) {
            return;
        }

        $lockFile = $this->lockFile();
        if (!file_exists($lockFile)) {
            return;
        }

        $raw = trim((string) file_get_contents($lockFile));
        $recordedPid = $raw === '' ? 0 : (int) $raw;

        if ($recordedPid === getmypid()) {
            @unlink($lockFile);
        }
    }

    /**
     * Execute the scheduled event.
     *
     * @param Container       $container
     * @param LoggerInterface $logger
     */
    public function run(Container $container, LoggerInterface $logger): void
    {
        if (!$this->acquireLock($logger)) {
            return;
        }

        try {
            $logger->info("Scheduled task started", ['task' => $this->getName()]);

            if ($this->jobClass !== null) {
                $job = $container->get($this->jobClass);
                if (!$job instanceof ScheduledJobInterface) {
                    throw new \RuntimeException("{$this->jobClass} must implement ScheduledJobInterface");
                }
                $job->handle();
            } elseif ($this->callback !== null) {
                ($this->callback)($container);
            }

            $logger->info("Scheduled task finished", ['task' => $this->getName()]);
        } catch (\Throwable $e) {
            $logger->error("Scheduled task failed", [
                'task'  => $this->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            $this->releaseLock();
        }
    }
}
