<?php
namespace Reactor\Console\Commands;

use Reactor\Database\Migrations\Migrator;
use Reactor\Contracts\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to rollback all migrations.
 */
class MigrateResetCommand extends Command
{
    protected static $defaultName = 'migrate:reset';
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
        $this->setDescription('Rollback all migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->migrator->reset();
            $output->writeln('<info>All migrations rolled back.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->logger->error('Reset command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
