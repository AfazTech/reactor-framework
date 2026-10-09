<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:monitor', description: 'Show the number of pending jobs in one or more queues')]
class QueueMonitorCommand extends Command
{
    public function __construct(private QueueManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'queue',
            null,
            InputOption::VALUE_OPTIONAL,
            'Comma-separated queue names to monitor',
            'default'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queues = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $input->getOption('queue'))
        )));

        if ($queues === []) {
            $queues = ['default'];
        }

        $table = new Table($output);
        $table->setHeaders(['Queue', 'Pending Jobs']);

        foreach ($queues as $queue) {
            $table->addRow([$queue, $this->manager->size($queue)]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
