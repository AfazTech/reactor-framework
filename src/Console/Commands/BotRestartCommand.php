<?php

namespace Reactor\Console\Commands;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Config;
use Reactor\Core\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restarts the polling bot process.
 *
 * The currently running polling process is identified through the lock
 * file that LockManager writes on startup
 * (storage/locks/{app}_polling.lock). The command sends SIGTERM so the
 * process can release the lock and exit cleanly, waits up to the
 * configured timeout, then spawns a fresh bot.php in the background.
 *
 * On Windows the POSIX signal API is not available, so a `taskkill`
 * based shutdown is used instead; the restart then continues normally.
 */
#[AsCommand(name: 'bot:restart', description: 'Restart the polling bot process')]
class BotRestartCommand extends Command
{
    private Paths $paths;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(Paths $paths, Config $config, LoggerInterface $logger)
    {
        parent::__construct();
        $this->paths = $paths;
        $this->config = $config;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'wait',
                'w',
                InputOption::VALUE_OPTIONAL,
                'Seconds to wait for the old process to shut down gracefully',
                5
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Send SIGKILL if the process does not stop within the timeout'
            )
            ->addOption(
                'no-start',
                null,
                InputOption::VALUE_NONE,
                'Stop the current process without starting a new one'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockFile = $this->lockFile();

        $stopped = $this->stopRunningProcess($input, $output, $lockFile);
        if ($stopped !== Command::SUCCESS) {
            return $stopped;
        }

        if ($input->getOption('no-start')) {
            $output->writeln('<info>Bot stopped. Not starting a new instance (--no-start).</info>');
            return Command::SUCCESS;
        }

        return $this->startBot($output);
    }

    /**
     * Stop the currently running polling process, if any.
     *
     * @return int Command::SUCCESS when it is safe to start a new
     *             process; Command::FAILURE when the old process could
     *             not be stopped.
     */
    private function stopRunningProcess(
        InputInterface $input,
        OutputInterface $output,
        string $lockFile
    ): int {
        if (!file_exists($lockFile)) {
            $output->writeln('<comment>No running polling process detected (no lock file).</comment>');
            return Command::SUCCESS;
        }

        $pid = (int) trim((string) file_get_contents($lockFile));

        if ($pid <= 0 || !$this->isProcessRunning($pid)) {
            $output->writeln('<comment>Stale lock file detected. Removing it.</comment>');
            @unlink($lockFile);
            return Command::SUCCESS;
        }

        $output->writeln("Stopping polling process (PID: {$pid})...");

        if (!$this->sendTermination($pid, false)) {
            $output->writeln(
                '<error>Failed to send termination signal to process ' . $pid . '.</error>'
            );
            return Command::FAILURE;
        }

        $wait = max(1, (int) $input->getOption('wait'));
        $deadline = time() + $wait;

        while (time() < $deadline) {
            if (!$this->isProcessRunning($pid)) {
                break;
            }
            usleep(200_000); // 200ms
        }

        if ($this->isProcessRunning($pid)) {
            if (!$input->getOption('force')) {
                $output->writeln(
                    '<error>Process ' . $pid . ' did not stop within ' . $wait . 's. '
                    . 'Use --force to send SIGKILL.</error>'
                );
                return Command::FAILURE;
            }

            $output->writeln('<comment>Process still running, sending SIGKILL...</comment>');
            $this->sendTermination($pid, true);
            usleep(500_000);

            if ($this->isProcessRunning($pid)) {
                $output->writeln('<error>Process ' . $pid . ' could not be terminated.</error>');
                return Command::FAILURE;
            }
        }

        // The LockManager signal handler normally removes the lock file
        // during shutdown, but a forced kill can leave it behind.
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        $output->writeln('<info>Polling process stopped.</info>');
        return Command::SUCCESS;
    }

    /**
     * Spawn a fresh bot.php process in the background.
     *
     * The child is started detached via `nohup` with output redirected
     * to /dev/null, so it survives the current console invocation and
     * does not spam the terminal.
     */
    private function startBot(OutputInterface $output): int
    {
        $botScript = $this->paths->base() . '/bot.php';

        if (!file_exists($botScript)) {
            $output->writeln('<error>bot.php not found at: ' . $botScript . '</error>');
            return Command::FAILURE;
        }

        $phpBinary = $this->resolvePhpBinary();

        $command = sprintf(
            'nohup %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($phpBinary),
            escapeshellarg($botScript)
        );

        $output->writeln("Starting bot: {$phpBinary} {$botScript}");

        $newPid = (int) trim((string) shell_exec($command));

        // Wait for the child to acquire its own lock file. A short
        // bounded wait keeps the command responsive while still
        // catching early bootstrap failures.
        $lockFile = $this->lockFile();
        $deadline = time() + 3;

        while (time() < $deadline) {
            if (file_exists($lockFile)) {
                $output->writeln(
                    '<info>Bot restarted successfully (new PID: ' . $newPid . ').</info>'
                );
                $this->logger->info('Bot restarted via console command', ['pid' => $newPid]);
                return Command::SUCCESS;
            }
            usleep(200_000);
        }

        $output->writeln(
            '<error>New bot process did not acquire the lock file. '
            . 'Check storage/logs/app.log for bootstrap errors.</error>'
        );
        return Command::FAILURE;
    }

    /**
     * Resolve the PHP binary to use for the spawned process.
     *
     * Falls back to the currently running interpreter when the path
     * configured in .env is not executable, so the command keeps
     * working on systems where PHP_BINARY points to a different
     * installation than the one referenced in bot.php_binary.
     */
    private function resolvePhpBinary(): string
    {
        $configured = $this->config->getPhpBinary();

        if ($configured !== '' && @is_executable($configured)) {
            return $configured;
        }

        return PHP_BINARY;
    }

    private function lockFile(): string
    {
        $appName = (string) $this->config->get('app.name', 'reactor');
        return $this->paths->storage() . '/locks/' . $appName . '_polling.lock';
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
     * Send a termination signal to the given PID.
     *
     * @param bool $force When true, send SIGKILL (or the Windows
     *                    equivalent) instead of SIGTERM.
     */
    private function sendTermination(int $pid, bool $force): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, $force ? SIGKILL : SIGTERM);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('taskkill /F /PID ' . $pid);
            return true;
        }

        return false;
    }
}
