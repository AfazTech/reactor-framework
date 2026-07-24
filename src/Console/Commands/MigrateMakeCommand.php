<?php
namespace Reactor\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Reactor\Core\Paths;

class MigrateMakeCommand extends Command
{
    protected static $defaultName = 'migrate:make';
    private Paths $paths;

    public function __construct(Paths $paths)
    {
        parent::__construct();
        $this->paths = $paths;
    }

    protected function configure(): void
    {
        $this->setDescription('Create a new migration file')
             ->addArgument('name', InputArgument::REQUIRED, 'Migration name (e.g., create_users_table)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $timestamp = date('Y_m_d_His');
        $fileName = $timestamp . '_' . $name . '.php';
        $path = $this->paths->database() . '/migrations/' . $fileName;

        $stub = $this->getStub($name);
        file_put_contents($path, $stub);

        $output->writeln("<info>Created migration: $fileName</info>");
        return Command::SUCCESS;
    }

    private function getStub(string $name): string
    {
        $className = $this->toClassName($name);
        return <<<PHP
<?php

namespace Reactor\Migrations;

use Reactor\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;

class $className extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
}
PHP;
    }

    private function toClassName(string $name): string
    {
        $parts = explode('_', $name);
        $parts = array_map('ucfirst', $parts);
        return implode('', $parts);
    }
}
