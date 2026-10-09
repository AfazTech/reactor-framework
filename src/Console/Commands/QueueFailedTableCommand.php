<?php
namespace Reactor\Console\Commands;

use Reactor\Core\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a versioned migration for the failed_jobs table.
 *
 * FailedJobRepository creates this table lazily on first use. This
 * command exists for users who prefer to track the schema change
 * through the regular migration system.
 */
#[AsCommand(name: 'queue:failed-table', description: 'Create a migration for the failed_jobs table')]
class QueueFailedTableCommand extends Command
{
    public function __construct(private Paths $paths)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Create the migration even if a similarly named file already exists'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->paths->database() . '/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = date('Y_m_d_His') . '_create_failed_jobs_table.php';
        $path = $dir . '/' . $name;

        if (file_exists($path) && !$input->getOption('force')) {
            $output->writeln("<comment>Migration already exists: {$name}</comment>");
            return Command::SUCCESS;
        }

        file_put_contents($path, $this->stub());

        $output->writeln("<info>Migration created: database/migrations/{$name}</info>");
        $output->writeln('<comment>Run "php reactor.php migrate" to apply it.</comment>');

        return Command::SUCCESS;
    }

    private function stub(): string
    {
        return <<<'PHP'
<?php

namespace App\Migrations;

use Reactor\Database\Migrations\Migration;
use Reactor\Contracts\DatabaseManagerInterface;

class CreateFailedJobsTable extends Migration
{
    public function __construct(DatabaseManagerInterface $db)
    {
        parent::__construct($db);
    }

    public function up(): void
    {
        if (!$this->db->schema()->hasTable('failed_jobs')) {
            $this->db->schema()->create('failed_jobs', function ($table) {
                $table->id();
                $table->string('queue')->index();
                $table->text('payload');
                $table->text('exception');
                $table->integer('failed_at')->index();
            });
        }
    }

    public function down(): void
    {
        $this->db->schema()->dropIfExists('failed_jobs');
    }
}
PHP;
    }
}
