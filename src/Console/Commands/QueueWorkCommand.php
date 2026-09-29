<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Process queued jobs')]
class QueueWorkCommand extends Command
{
    private QueueManager $queueManager;

    public function __construct(QueueManager $queueManager)
    {
        parent::__construct();
        $this->queueManager = $queueManager;
    }

    protected function configure(): void
    {
        $this->addOption('queue', 'q', InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
             ->addOption('max', 'm', InputOption::VALUE_OPTIONAL, 'Max jobs to process', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        $max = (int) $input->getOption('max');
        $this->queueManager->process($queue, $max);
        $output->writeln("<info>Processed up to {$max} jobs from queue: {$queue}</info>");
        return Command::SUCCESS;
    }
}
