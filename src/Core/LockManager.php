<?php

namespace Reactor\Core;

use Reactor\Contracts\LoggerInterface;

/**
 * File-based lock manager to prevent multiple processes from running.
 *
 * The lock file is created atomically with fopen(..., 'x') so that two
 * processes cannot both believe they acquired the lock.
 */
class LockManager
{
    private LoggerInterface $logger;
    private string $lockFile;

    public function __construct(LoggerInterface $logger, string $lockFile)
    {
        $this->logger = $logger;
        $this->lockFile = $lockFile;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $output = [];
            @exec('tasklist /FI "PID eq ' . $pid . '"', $output);
            foreach ($output as $line) {
                if (str_contains($line, (string) $pid)) {
                    return true;
                }
            }
            return false;
        }

        return file_exists("/proc/{$pid}");
    }

    /**
     * Return the PID of another live process holding the lock, or null.
     */
    public function isHeldByAnotherProcess(): ?int
    {
        if (!file_exists($this->lockFile)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($this->lockFile));

        if ($pid <= 0 || $pid === getmypid()) {
            return null;
        }

        return $this->isProcessRunning($pid) ? $pid : null;
    }

    /**
     * Acquire the lock atomically.
     *
     * @throws \RuntimeException When another live process holds the lock.
     */
    public function acquire(): void
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = @fopen($this->lockFile, 'x');

        if ($handle === false) {
            $pid = (int) trim((string) @file_get_contents($this->lockFile));

            if ($pid > 0 && $this->isProcessRunning($pid)) {
                throw new \RuntimeException(
                    "Lock file exists and process is running (PID: {$pid})"
                );
            }

            $this->logger->warning('Stale lock file found, removing', ['pid' => $pid]);
            @unlink($this->lockFile);

            $handle = @fopen($this->lockFile, 'x');
            if ($handle === false) {
                throw new \RuntimeException(
                    'Unable to acquire lock: another process raced for it.'
                );
            }
        }

        fwrite($handle, (string) getmypid());
        fclose($handle);

        register_shutdown_function([$this, 'release']);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () {
                $this->release();
                exit(0);
            });
            pcntl_signal(SIGTERM, function () {
                $this->release();
                exit(0);
            });
        }
    }

    public function release(): void
    {
        if (!file_exists($this->lockFile)) {
            return;
        }

        $pid = (int) trim((string) file_get_contents($this->lockFile));

        if ($pid === getmypid()) {
            @unlink($this->lockFile);
        }
    }
}
