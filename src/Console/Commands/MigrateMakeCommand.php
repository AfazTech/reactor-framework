<?php
namespace Reactor\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Reactor\Core\Config;
use Reactor\Core\Paths;

#[AsCommand(name: 'migrate:make', description: 'Create a new migration file')]
class MigrateMakeCommand extends Command
{
    private Paths $paths;
    private Config $config;

    public function __construct(Paths $paths, Config $config)
    {
        parent::__construct();
        $this->paths = $paths;
        $this->config = $config;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Migration name (e.g., create_users_table)')
             ->addOption('create', null, InputOption::VALUE_OPTIONAL, 'The table to be created')
             ->addOption('table', null, InputOption::VALUE_OPTIONAL, 'The table to be updated')
             ->setHelp(
                 "Examples:\n" .
                 "  php reactor.php migrate:make create_posts_table\n" .
                 "  php reactor.php migrate:make --create=posts\n" .
                 "  php reactor.php migrate:make --table=posts"
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $create = $input->getOption('create');
        $table = $input->getOption('table');

        // Mutually exclusive flags.
        if ($create !== null && $table !== null) {
            $output->writeln('<error>Options --create and --table cannot be used together.</error>');
            return Command::INVALID;
        }

        $name = $this->resolveName($name, $create, $table);

        if ($name === null) {
            $output->writeln('<error>Missing migration name. Provide a name argument or use --create / --table.</error>');
            return Command::INVALID;
        }

        $timestamp = date('Y_m_d_His');
        $fileName = $timestamp . '_' . $name . '.php';
        $migrationsDir = $this->paths->database() . '/migrations';

        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }

        $path = $migrationsDir . '/' . $fileName;

        if (file_exists($path)) {
            $output->writeln("<error>Migration already exists: $fileName</error>");
            return Command::FAILURE;
        }

        $stub = $this->getStub($name, $create, $table);
        file_put_contents($path, $stub);

        $output->writeln("<info>Created migration: $fileName</info>");
        return Command::SUCCESS;
    }

    /**
     * Determine the final migration name from the raw inputs.
     *
     * - Explicit name is used as-is.
     * - --create=foo produces create_foo_table.
     * - --table=foo  produces update_foo_table.
     */
    private function resolveName(?string $name, ?string $create, ?string $table): ?string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (is_string($create) && $create !== '') {
            return 'create_' . $create . '_table';
        }

        if (is_string($table) && $table !== '') {
            return 'update_' . $table . '_table';
        }

        return null;
    }

    private function getStub(string $name, ?string $create, ?string $table): string
    {
        $className = $this->toClassName($name);
        $namespace = (string) $this->config->get('database.migrations.namespace', 'App\\Migrations');

        if (is_string($create) && $create !== '') {
            return $this->createTableStub($namespace, $className, $create);
        }
        if (is_string($table) && $table !== '') {
            return $this->updateTableStub($namespace, $className, $table);
        }
        return $this->plainStub($namespace, $className);
    }

    private function plainStub(string $namespace, string $className): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Reactor\\Database\\Migrations\\Migration;
use Reactor\\Contracts\\DatabaseManagerInterface;

class $className extends Migration
{
    public function __construct(DatabaseManagerInterface \$db)
    {
        parent::__construct(\$db);
    }

    public function up(): void
    {
    }

    public function down(): void
    {
    }
}
PHP;
    }

    private function createTableStub(string $namespace, string $className, string $table): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Reactor\\Database\\Migrations\\Migration;
use Reactor\\Contracts\\DatabaseManagerInterface;

class $className extends Migration
{
    public function __construct(DatabaseManagerInterface \$db)
    {
        parent::__construct(\$db);
    }

    public function up(): void
    {
        if (!\$this->db->schema()->hasTable('$table')) {
            \$this->db->schema()->create('$table', function (\$table) {
                \$table->id();
                \$table->timestamps();
            });
        }
    }

    public function down(): void
    {
        \$this->db->schema()->dropIfExists('$table');
    }
}
PHP;
    }

    private function updateTableStub(string $namespace, string $className, string $table): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Reactor\\Database\\Migrations\\Migration;
use Reactor\\Contracts\\DatabaseManagerInterface;

class $className extends Migration
{
    public function __construct(DatabaseManagerInterface \$db)
    {
        parent::__construct(\$db);
    }

    public function up(): void
    {
        \$this->db->schema()->table('$table', function (\$table) {
            // \$table->string('example')->nullable();
        });
    }

    public function down(): void
    {
        \$this->db->schema()->table('$table', function (\$table) {
            // \$table->dropColumn('example');
        });
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
