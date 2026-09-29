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

    public function getName(): string
    {
        return $this->name ?? ($this->jobClass ?? spl_object_hash($this));
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

    private function acquireLock(): bool
    {
        if (!$this->preventOverlap) {
            return true;
        }

        if (!is_dir($this->lockDirectory)) {
            mkdir($this->lockDirectory, 0755, true);
        }

        $lockFile = $this->lockFile();

        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < ($this->overlapExpiryMinutes * 60)) {
            return false;
        }

        touch($lockFile);
        return true;
    }

    private function releaseLock(): void
    {
        if (!$this->preventOverlap) {
            return;
        }

        $lockFile = $this->lockFile();
        if (file_exists($lockFile)) {
            unlink($lockFile);
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
        if (!$this->acquireLock()) {
            $logger->warning("Scheduled task skipped, still running", ['task' => $this->getName()]);
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
                'task' => $this->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } finally {
            $this->releaseLock();
        }
    }
}
