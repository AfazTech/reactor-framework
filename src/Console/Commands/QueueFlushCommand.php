<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\FailedJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:flush', description: 'Delete all failed queue jobs')]
class QueueFlushCommand extends Command
{
    public function __construct(private FailedJobRepository $failedJobs)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->failedJobs->flush();

        $output->writeln("<info>Deleted {$count} failed job(s).</info>");

        return Command::SUCCESS;
    }
}
