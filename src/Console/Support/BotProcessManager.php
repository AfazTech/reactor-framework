<?php

namespace Reactor\Console\Support;

use Reactor\Core\Config;
use Reactor\Core\Paths;

/**
 * Helper for managing the lifecycle of the polling bot process.
 *
 * The polling process records its PID in a lock file under
 * storage/locks. This helper reads that file, checks whether the
 * recorded process is still alive, sends it a termination signal, and
 * spawns a fresh detached instance when asked to.
 *
 * The PID stored in the lock file is the authoritative source of the
 * running poller's identity: shell backgrounding (`&`, `$!`) does not
 * reliably return the PHP process's own PID on every platform, so the
 * spawning method does not attempt to capture it and instead relies on
 * LockManager to write the real PID once the child is fully up.
 */
class BotProcessManager
{
    /**
     * SIGTERM: request a graceful shutdown.
     *
     * Defined as a constant because ext-pcntl may not be loaded, in
     * which case the SIGTERM/SIGKILL symbols would be undefined even
     * though the numeric value is stable across every POSIX platform.
     */
    private const SIGTERM = 15;

    /**
     * SIGKILL: force an immediate termination.
     */
    private const SIGKILL = 9;

    public function __construct(
        private Paths $paths,
        private Config $config,
    ) {
    }

    /**
     * Absolute path to the polling lock file used by App::start().
     */
    public function lockFile(): string
    {
        $appName = (string) $this->config->get('app.name', 'reactor');
        return $this->paths->storage() . '/locks/' . $appName . '_polling.lock';
    }

    /**
     * Return the PID of the currently running poller, or null when no
     * live poller is detected.
     *
     * A stale lock file (whose recorded PID is no longer running) is
     * treated as "no running process" and is not removed here; the
     * stop() method handles the cleanup.
     */
    public function getRunningPid(): ?int
    {
        $lockFile = $this->lockFile();

        if (!file_exists($lockFile)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($lockFile));

        if ($pid <= 0) {
            return null;
        }

        return $this->isRunning($pid) ? $pid : null;
    }

    /**
     * Check whether a process with the given PID is still alive.
     */
    public function isRunning(int $pid): bool
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
     * Stop the currently running polling process.
     *
     * @param int  $waitSeconds  Graceful shutdown window before SIGKILL.
     * @param bool $force        When true, escalate to SIGKILL on timeout.
     *
     * @return bool True when no live process remains afterwards
     *              (including the case where none was running).
     */
    public function stop(int $waitSeconds = 5, bool $force = false): bool
    {
        $pid = $this->getRunningPid();

        if ($pid === null) {
            $this->removeStaleLock();
            return true;
        }

        if (!$this->sendSignal($pid, self::SIGTERM)) {
            return false;
        }

        $deadline = time() + max(1, $waitSeconds);
        while (time() < $deadline && $this->isRunning($pid)) {
            usleep(200_000); // 200ms
        }

        if ($this->isRunning($pid)) {
            if (!$force) {
                return false;
            }

            $this->sendSignal($pid, self::SIGKILL);
            usleep(500_000);

            if ($this->isRunning($pid)) {
                return false;
            }
        }

        $this->removeStaleLock();
        return true;
    }

    /**
     * Spawn a new detached bot process running `reactor.php start`.
     *
     * The caller should poll getRunningPid() afterwards to confirm the
     * child actually bootstrapped and acquired its lock file.
     *
     * @return bool True when the shell command was dispatched.
     */
    public function spawnDetached(): bool
    {
        $reactorScript = $this->paths->base() . '/reactor.php';

        if (!file_exists($reactorScript)) {
            return false;
        }

        $logFile = $this->paths->logs() . '/bot-output.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $phpBinary = $this->resolvePhpBinary();

        // The background job's `$!` is the shell's PID, not the PHP
        // process's, so it is intentionally not captured here. The real
        // PID will be recorded in the lock file by LockManager once the
        // child is running.
        $command = sprintf(
            'cd %s && nohup %s %s start >> %s 2>&1 &',
            escapeshellarg($this->paths->base()),
            escapeshellarg($phpBinary),
            escapeshellarg($reactorScript),
            escapeshellarg($logFile)
        );

        @shell_exec($command);

        return true;
    }

    /**
     * Remove the lock file left behind by a process that has exited.
     */
    private function removeStaleLock(): void
    {
        $lockFile = $this->lockFile();
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * Resolve the PHP binary to use for the spawned process.
     *
     * Prefers the value configured via bot.php_binary. When that path
     * is not executable (different host, PHP upgraded, ...) falls back
     * to the interpreter currently running the CLI command.
     */
    private function resolvePhpBinary(): string
    {
        $configured = $this->config->getPhpBinary();

        if ($configured !== '' && @is_executable($configured)) {
            return $configured;
        }

        return PHP_BINARY;
    }

    /**
     * Send a signal to the given PID, using the platform-appropriate
     * mechanism. On Windows, taskkill is used regardless of the signal
     * value because POSIX signals are not available.
     */
    private function sendSignal(int $pid, int $signal): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, $signal);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('taskkill /F /PID ' . $pid);
            return true;
        }

        return false;
    }
}
