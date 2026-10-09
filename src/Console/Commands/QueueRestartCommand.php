<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\Signals;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:restart', description: 'Signal all queue workers to exit after the current job')]
class QueueRestartCommand extends Command
{
    public function __construct(private Signals $signals)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->signals->restart();

        $output->writeln('<info>Queue restart signal broadcasted.</info>');
        $output->writeln(
            '<comment>Workers will exit after completing their current job. '
            . 'Use a supervisor (systemd/supervisord) to restart them automatically.</comment>'
        );

        return Command::SUCCESS;
    }
}
