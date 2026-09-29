<?php
namespace Reactor\Database\Migrations;

use Reactor\Contracts\LoggerInterface;

/**
 * File‑based lock to prevent concurrent migrations.
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

    public function acquire(): void
    {
        if (file_exists($this->lockFile)) {
            $pid = (int) trim((string) file_get_contents($this->lockFile));
            if ($pid > 0 && $this->isProcessRunning($pid)) {
                throw new \RuntimeException(
                    "Migration process already running (PID: {$pid}). Remove " . $this->lockFile . " if stale."
                );
            }
            $this->logger->warning("Stale migration lock file found, removing", ['pid' => $pid]);
            unlink($this->lockFile);
        }

        $lockDir = dirname($this->lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        file_put_contents($this->lockFile, (string) getmypid());
    }

    public function release(): void
    {
        if (file_exists($this->lockFile)) {
            $ownerPid = (int) trim((string) file_get_contents($this->lockFile));
            if ($ownerPid === getmypid()) {
                unlink($this->lockFile);
            }
        }
    }
}
