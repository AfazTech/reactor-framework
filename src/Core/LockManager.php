<?php

namespace Reactor\Core;

use Reactor\Contracts\LoggerInterface;

/**
 * Simple file‑based lock manager to prevent multiple processes from running.
 */
class LockManager
{
    private LoggerInterface $logger;
    private string $lockFile;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger   Logger.
     * @param string          $lockFile Path to the lock file.
     */
    public function __construct(LoggerInterface $logger, string $lockFile)
    {
        $this->logger = $logger;
        $this->lockFile = $lockFile;
    }

    /**
     * Check if a process with the given PID is still running.
     *
     * @param int $pid Process ID.
     *
     * @return bool
     */
    private function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $output = [];
            exec('tasklist /FI "PID eq ' . $pid . '"', $output);
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
     * Acquire the lock.
     *
     * @throws \RuntimeException If lock already held by a running process.
     */
    public function acquire(): void
    {
        if (file_exists($this->lockFile)) {
            $pid = (int) trim(file_get_contents($this->lockFile));
            if ($pid > 0 && $this->isProcessRunning($pid)) {
                throw new \RuntimeException("Lock file exists and process is running (PID: {$pid})");
            }
            $this->logger->warning('Stale lock file found, removing', ['pid' => $pid]);
            unlink($this->lockFile);
        }

        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->lockFile, (string) getmypid());
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

    /**
     * Release the lock if owned by the current process.
     */
    public function release(): void
    {
        if (file_exists($this->lockFile)) {
            $pid = (int) trim(file_get_contents($this->lockFile));
            if ($pid === getmypid()) {
                unlink($this->lockFile);
            }
        }
    }
}
