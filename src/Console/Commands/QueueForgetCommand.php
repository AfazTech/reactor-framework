<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\FailedJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:forget', description: 'Delete a single failed queue job')]
class QueueForgetCommand extends Command
{
    public function __construct(private FailedJobRepository $failedJobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The failed job ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('id');

        if (!$this->failedJobs->forget($id)) {
            $output->writeln("<error>Failed job {$id} not found.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Failed job {$id} deleted.</info>");

        return Command::SUCCESS;
    }
}
