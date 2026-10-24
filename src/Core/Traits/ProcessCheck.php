<?php
namespace Reactor\Core\Traits;

/**
 * Helper trait for checking whether a process is still alive.
 *
 * Used by file-based lock managers to distinguish a stale lock (the
 * recorded PID is dead or unreadable) from a live lock (the recorded
 * PID still belongs to a running process).
 *
 * The check is intentionally best-effort. On POSIX systems posix_kill
 * with signal 0 is the fastest path. On Windows, tasklist is queried.
 * On systems where neither is available, /proc/{pid} is the fallback.
 */
trait ProcessCheck
{
    protected function isProcessRunning(int $pid): bool
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
}
