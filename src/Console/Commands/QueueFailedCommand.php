<?php
namespace Reactor\Console\Commands;

use Reactor\Queue\FailedJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:failed', description: 'List all failed queue jobs')]
class QueueFailedCommand extends Command
{
    public function __construct(private FailedJobRepository $failedJobs)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobs = $this->failedJobs->all();

        if ($jobs === []) {
            $output->writeln('<info>No failed jobs.</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Queue', 'Failed At', 'Job', 'Error']);

        foreach ($jobs as $job) {
            $payload = json_decode((string) $job->payload, true);
            $class = is_array($payload) ? ($payload['job'] ?? 'unknown') : 'unknown';
            $exception = trim((string) $job->exception);
            $firstLine = explode("\n", $exception)[0] ?? '';
            $error = mb_substr($firstLine, 0, 80);

            $table->addRow([
                $job->id,
                $job->queue,
                date('Y-m-d H:i:s', (int) $job->failed_at),
                $class,
                $error,
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
