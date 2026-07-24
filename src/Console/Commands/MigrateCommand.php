<?php
namespace Reactor\Console\Commands;

use Reactor\Database\Migrations\Migrator;
use Reactor\Contracts\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to run all pending database migrations.
 */
class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';
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
        $this->setDescription('Run all pending migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->migrator->run();
            $output->writeln('<info>Migrations executed successfully.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->logger->error('Migration command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
