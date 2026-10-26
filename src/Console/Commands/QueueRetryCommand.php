<?php

namespace Reactor\Console\Commands;

use Amp\Future;
use Reactor\Queue\FailedJobRepository;
use Reactor\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Amp\async;

#[AsCommand(name: 'queue:retry', description: 'Retry a failed queue job, or all of them')]
class QueueRetryCommand extends Command
{
    public function __construct(
        private FailedJobRepository $failedJobs,
        private QueueManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'id',
            InputArgument::REQUIRED,
            'The failed job ID, or "all" to retry every failed job'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');

        if ($id === 'all') {
            return $this->retryAll($output);
        }

        if (!ctype_digit($id)) {
            $output->writeln('<error>The id must be a positive integer or the literal "all".</error>');
            return Command::INVALID;
        }

        return $this->retryOne((int) $id, $output);
    }

    private function retryOne(int $id, OutputInterface $output): int
    {
        $job = $this->failedJobs->find($id);
        if ($job === null) {
            $output->writeln("<error>Failed job {$id} not found.</error>");
            return Command::FAILURE;
        }

        if (!$this->requeue($job, $output)->await()) {
            return Command::FAILURE;
        }

        $this->failedJobs->forget($id);

        return Command::SUCCESS;
    }

    private function retryAll(OutputInterface $output): int
    {
        $jobs = $this->failedJobs->all();
        $requeued = 0;

        foreach ($jobs as $job) {
            if ($this->requeue($job, $output)->await()) {
                $requeued++;
            }
        }

        $this->failedJobs->flush();

        $output->writeln("<info>Requeued {$requeued} failed job(s).</info>");

        return Command::SUCCESS;
    }

    /**
     * Push a failed job back onto its queue.
     *
     * Returns a Future<bool> so callers can decide whether to await it
     * synchronously (as retryOne()/retryAll() do) or schedule the work
     * concurrently and await later. The Future resolves to true on
     * success and false when the payload is invalid or the push fails.
     *
     * @return Future<bool>
     */
    private function requeue(object $job, OutputInterface $output): Future
    {
        return async(function () use ($job, $output): bool {
            $payload = json_decode((string) $job->payload, true);
            if (!is_array($payload) || !isset($payload['job'])) {
                $output->writeln(
                    "<error>Failed job {$job->id} has an invalid payload; skipping.</error>"
                );
                return false;
            }

            $this->manager->push(
                (string) $payload['job'],
                (array) ($payload['data'] ?? []),
                (string) $job->queue,
            )->await();

            $output->writeln(
                "<info>Failed job {$job->id} pushed back onto queue \"{$job->queue}\".</info>"
            );

            return true;
        });
    }
}
