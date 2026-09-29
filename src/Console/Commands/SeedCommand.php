<?php
namespace Reactor\Console\Commands;

use Reactor\Database\Seeders\SeederRunner;
use Reactor\Contracts\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:seed', description: 'Seed the database with records')]
class SeedCommand extends Command
{
    private LoggerInterface $logger;
    private SeederRunner $seederRunner;

    public function __construct(LoggerInterface $logger, SeederRunner $seederRunner)
    {
        parent::__construct();
        $this->logger = $logger;
        $this->seederRunner = $seederRunner;
    }

    protected function configure(): void
    {
        $this->addOption(
            'class',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Seeder class to run',
            'Reactor\\Seeders\\DatabaseSeeder'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $class = (string) $input->getOption('class');
        try {
            $this->seederRunner->run($class);
            $output->writeln('<info>Seeding completed.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $this->logger->error('Seed command failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
