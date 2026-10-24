<?php
namespace Reactor\Console\Commands;

use Reactor\Console\Support\ConsoleMigrationListener;
use Reactor\Database\Migrations\Migrator;
use Reactor\Contracts\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Run all pending migrations')]
class MigrateCommand extends Command
{
    private LoggerInterface $logger;
    private Migrator $migrator;

    public function __construct(LoggerInterface $logger, Migrator $migrator)
    {
        parent::__construct();
        $this->logger = $logger;
        $this->migrator = $migrator;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->migrator->ensureReady();

            // Show the current state of every discovered migration so the
            // operator can see which ones already ran (and when) and which
            // ones are pending. Mirrors Laravel's behaviour of surfacing
            // the tracking table before running new migrations.
            $status = $this->migrator->status();
            if (!empty($status)) {
                $this->renderStatusTable($output, $status);
                $output->writeln('');
            }

            $pending = $this->migrator->getPendingMigrations();
            if (empty($pending)) {
                $output->writeln('<info>Nothing to migrate.</info>');
                return Command::SUCCESS;
            }

            $output->writeln(sprintf(
                '<comment>Running %d pending migration(s)...</comment>',
                count($pending)
            ));
            $output->writeln('');

            $this->migrator->setListener(new ConsoleMigrationListener($output));

            try {
                $this->migrator->run();
            } finally {
                // Always detach the listener so that a subsequent operation
                // on the same Migrator instance does not accidentally write
                // per-migration output to a stale console.
                $this->migrator->setListener(null);
            }

            $output->writeln('');
            $output->writeln('<info>Migrations executed successfully.</info>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->logger->error('Migration command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Render the tracking-table snapshot as a Symfony Console table.
     *
     * @param array<int, array{migration: string, batch: int|null, ran: bool, ran_at: string|null, source: string}> $status
     */
    private function renderStatusTable(OutputInterface $output, array $status): void
    {
        $table = new Table($output);
        $table->setHeaders(['Migration', 'Batch', 'Ran At', 'Status']);

        foreach ($status as $row) {
            $table->addRow([
                $row['migration'],
                $row['batch'] ?? '-',
                $row['ran_at'] ?? '-',
                $row['ran'] ? '<info>Ran</info>' : '<comment>Pending</comment>',
            ]);
        }

        $table->render();
    }
}
