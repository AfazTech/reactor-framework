<?php

namespace Reactor\Console\Commands;

use Reactor\Console\Support\BotProcessManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restart the polling bot process.
 *
 * The current process is stopped first (using the same logic as the
 * stop command), then a fresh instance is spawned in the background
 * via `reactor.php start`. The command reports success only once the
 * new process has acquired its lock file, so callers can rely on the
 * exit code.
 */
#[AsCommand(name: 'restart', description: 'Restart the polling bot process')]
class RestartCommand extends Command
{
    public function __construct(private BotProcessManager $processManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'wait',
                'w',
                InputOption::VALUE_OPTIONAL,
                'Seconds to wait for the old process to shut down',
                5
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Send SIGKILL if the old process does not stop within the timeout'
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
        $pid = $this->processManager->getRunningPid();

        if ($pid !== null) {
            $output->writeln("Stopping polling process (PID: {$pid})...");

            $wait = (int) $input->getOption('wait');
            $force = (bool) $input->getOption('force');

            if (!$this->processManager->stop($wait, $force)) {
                $output->writeln(
                    '<error>Process ' . $pid . ' could not be stopped. '
                    . 'Use --force to send SIGKILL.</error>'
                );
                return Command::FAILURE;
            }

            $output->writeln('<info>Polling process stopped.</info>');
        } else {
            $output->writeln('<comment>No running polling process detected.</comment>');
        }

        if ($input->getOption('no-start')) {
            $output->writeln('<info>Not starting a new instance (--no-start).</info>');
            return Command::SUCCESS;
        }

        $output->writeln('Starting bot...');

        if (!$this->processManager->spawnDetached()) {
            $output->writeln('<error>Failed to spawn a new bot process.</error>');
            return Command::FAILURE;
        }

        // Wait for the new process to acquire its lock file. The lock
        // file's PID is authoritative, so we read it once it appears.
        $deadline = time() + 5;
        $newPid = null;

        while (time() < $deadline) {
            $newPid = $this->processManager->getRunningPid();
            if ($newPid !== null) {
                break;
            }
            usleep(200_000);
        }

        if ($newPid === null) {
            $output->writeln(
                '<error>New bot process did not acquire the lock file. '
                . 'Check storage/logs/bot-output.log and storage/logs/app.log.</error>'
            );
            return Command::FAILURE;
        }

        $output->writeln(
            '<info>Bot restarted successfully (new PID: ' . $newPid . ').</info>'
        );
        return Command::SUCCESS;
    }
}
