<?php

namespace Reactor\Database\Migrations;

use Reactor\Contracts\LoggerInterface;

/**
 * File-based lock to prevent concurrent migrations.
 *
 * Uses fopen(..., 'x') for atomic creation.
 */
class MigrationLock
{
    private string $lockFile;
    private LoggerInterface $logger;

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
                    "Migration process already running (PID: {$pid}). Remove "
                    . $this->lockFile . ' if stale.'
                );
            }

            $this->logger->warning('Stale migration lock file found, removing', ['pid' => $pid]);
            @unlink($this->lockFile);

            $handle = @fopen($this->lockFile, 'x');
            if ($handle === false) {
                throw new \RuntimeException(
                    'Unable to acquire migration lock: another process raced for it.'
                );
            }
        }

        fwrite($handle, (string) getmypid());
        fclose($handle);
    }

    public function release(): void
    {
        if (!file_exists($this->lockFile)) {
            return;
        }

        $ownerPid = (int) trim((string) file_get_contents($this->lockFile));

        if ($ownerPid === getmypid()) {
            @unlink($this->lockFile);
        }
    }
}
