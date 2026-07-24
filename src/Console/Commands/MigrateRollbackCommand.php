<?php
namespace Reactor\Console\Commands;

use Reactor\Database\Migrations\Migrator;
use Reactor\Contracts\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to rollback the last migration batch(es).
 */
class MigrateRollbackCommand extends Command
{
    protected static $defaultName = 'migrate:rollback';
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
        $this->setDescription('Rollback the last migration batch')
             ->addOption('step', 's', InputOption::VALUE_OPTIONAL, 'Number of batches to rollback', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $step = (int)$input->getOption('step');
        try {
            $this->migrator->rollback($step);
            $output->writeln('<info>Rollback completed.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->logger->error('Rollback command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
