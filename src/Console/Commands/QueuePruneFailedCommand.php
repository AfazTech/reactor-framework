<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\FailedJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:prune-failed', description: 'Delete failed jobs older than the given number of hours')]
class QueuePruneFailedCommand extends Command
{
    public function __construct(private FailedJobRepository $failedJobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'hours',
            null,
            InputOption::VALUE_OPTIONAL,
            'Delete failed jobs older than this many hours',
            24
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = (int) $input->getOption('hours');

        if ($hours <= 0) {
            $output->writeln('<error>--hours must be a positive integer.</error>');
            return Command::INVALID;
        }

        $count = $this->failedJobs->pruneOlderThan($hours);

        $output->writeln("<info>Deleted {$count} failed job(s) older than {$hours} hour(s).</info>");

        return Command::SUCCESS;
    }
}
