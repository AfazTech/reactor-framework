<?php
namespace Reactor\Console\Commands;

use Reactor\Database\Migrations\Migrator;
use Reactor\Contracts\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to show the status of all migrations.
 */
class MigrateStatusCommand extends Command
{
    protected static $defaultName = 'migrate:status';
    private LoggerInterface $logger;
    private Migrator $migrator;

    public function __construct(LoggerInterface $logger, Migrator $migrator)
    {
        parent::__construct();
        $this->logger = $logger;
        $this->migrator = $migrator;
    }

    protected function configure(): void
    {
        $this->setDescription('Show the status of each migration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->migrator->status();

        if (empty($status)) {
            $output->writeln('<comment>No migrations found.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Migration', 'Batch', 'Status']);

        foreach ($status as $row) {
            $table->addRow([
                $row['migration'],
                $row['batch'] ?? '-',
                $row['ran'] ? '<info>Ran</info>' : '<comment>Pending</comment>',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }
}
