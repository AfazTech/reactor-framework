<?php

namespace Reactor\Console\Commands;

use Reactor\Console\Support\BotProcessManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stop the running polling bot process.
 *
 * The process is identified through its lock file and asked to shut
 * down gracefully with SIGTERM. If it does not exit within the
 * configured timeout, SIGKILL is sent only when --force is passed.
 */
#[AsCommand(name: 'stop', description: 'Stop the running polling bot process')]
class StopCommand extends Command
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
                'Seconds to wait for the process to shut down gracefully',
                5
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Send SIGKILL if the process does not stop within the timeout'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pid = $this->processManager->getRunningPid();

        if ($pid === null) {
            $output->writeln('<comment>No running polling process detected.</comment>');
            return Command::SUCCESS;
        }

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
        return Command::SUCCESS;
    }
}
