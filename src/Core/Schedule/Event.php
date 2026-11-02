<?php
namespace Reactor\Core\Schedule;

use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\ScheduledJobInterface;
use Reactor\Core\Container;

/**
 * A scheduled event that can be configured with a cron expression
 * and optional overlap prevention.
 *
 * The lock directory defaults to the host application's storage path
 * (resolved via the storage_path() helper) so the framework never
 * writes into its own package directory inside the Composer vendor
 * tree. A custom directory can be supplied for advanced setups.
 *
 * Overlap prevention uses a non-blocking OS file lock held for the entire
 * execution. The persistent lock file must not be unlinked: replacing its
 * inode would let another process acquire a different lock for the same task.
 * The OS releases the lock when the process exits, including after a crash.
 * overlapExpiryMinutes only controls warnings; it never expires a live lock.
 */
class Event
{
    private string $expression = '* * * * *';
    private ?string $name = null;
    private bool $preventOverlap = false;
    private int $overlapExpiryMinutes = 5;
    private $callback;
    private ?string $jobClass = null;
    private string $lockDirectory;

    /** @var resource|null */
    private $lockHandle = null;

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
     * recover from a genuinely hung task, terminate the process so the OS
     * releases its file lock.
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

        if ($this->lockHandle !== null) {
            return false;
        }

        if (!is_dir($this->lockDirectory)
            && !@mkdir($this->lockDirectory, 0755, true)
            && !is_dir($this->lockDirectory)
        ) {
            throw new \RuntimeException('Cannot create schedule lock directory.');
        }

        $lockFile = $this->lockFile();
        $handle = @fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open scheduled-task lock: ' . $lockFile);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $age = time() - (int) (fstat($handle)['mtime'] ?? time());
            fclose($handle);
            $context = ['task' => $taskName, 'age_seconds' => $age];
            if ($this->overlapExpiryMinutes > 0 && $age >= $this->overlapExpiryMinutes * 60) {
                $logger->warning('Scheduled task is still running beyond overlapExpiryMinutes', $context);
            } else {
                $logger->debug('Scheduled task skipped: another instance is running', $context);
            }
            return false;
        }

        if (!ftruncate($handle, 0) || fwrite($handle, (string) getmypid()) === false || !fflush($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new \RuntimeException('Cannot write scheduled-task lock: ' . $lockFile);
        }
        $this->lockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        // Keep the inode in place so every contender locks the same file.
        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
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
