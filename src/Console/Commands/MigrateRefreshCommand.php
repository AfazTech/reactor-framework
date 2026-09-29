<?php
namespace Reactor\Console\Commands;

use Reactor\Database\Migrations\Migrator;
use Reactor\Contracts\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:refresh', description: 'Reset and re-run all migrations')]
class MigrateRefreshCommand extends Command
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
            $this->migrator->refresh();
            $output->writeln('<info>Migrations refreshed.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->logger->error('Refresh command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
