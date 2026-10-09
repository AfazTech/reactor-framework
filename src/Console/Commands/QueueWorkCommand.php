<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\Worker;
use Reactor\Queue\WorkerOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Start a queue worker')]
class QueueWorkCommand extends Command
{
    public function __construct(private Worker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'queue',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated queue names, in priority order (highest first)',
                'default'
            )
            ->addOption(
                'once',
                null,
                InputOption::VALUE_NONE,
                'Process a single job and exit'
            )
            ->addOption(
                'stop-when-empty',
                null,
                InputOption::VALUE_NONE,
                'Process until the queue is empty, then exit'
            )
            ->addOption(
                'tries',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum attempts per job before it is marked as failed',
                3
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seconds a single job may run before timing out (0 = no timeout)',
                60
            )
            ->addOption(
                'sleep',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seconds to sleep between polls when the queue is empty',
                3
            )
            ->addOption(
                'max',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Maximum jobs to process before exiting (0 = unlimited)',
                0
            )
            ->addOption(
                'max-time',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum seconds to run before exiting (0 = unlimited)',
                0
            )
            ->addOption(
                'memory',
                null,
                InputOption::VALUE_OPTIONAL,
                'Memory limit in MB (0 = unlimited)',
                128
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

        $options = new WorkerOptions(
            queue: count($queues) === 1 ? $queues[0] : $queues,
            tries: (int) $input->getOption('tries'),
            timeout: (int) $input->getOption('timeout'),
            sleep: (int) $input->getOption('sleep'),
            maxJobs: (int) $input->getOption('max'),
            maxTime: (int) $input->getOption('max-time'),
            memory: (int) $input->getOption('memory'),
            once: (bool) $input->getOption('once'),
            stopWhenEmpty: (bool) $input->getOption('stop-when-empty'),
        );

        $output->writeln('<info>Queue worker starting...</info>');
        $processed = $this->worker->work($options);
        $output->writeln("<info>Queue worker stopped. Processed {$processed} job(s).</info>");

        return Command::SUCCESS;
    }
}
