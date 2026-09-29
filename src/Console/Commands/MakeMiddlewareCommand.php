<?php
namespace Reactor\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Reactor\Core\Paths;

#[AsCommand(name: 'make:middleware', description: 'Create a new middleware class')]
class MakeMiddlewareCommand extends Command
{
    private Paths $paths;

    public function __construct(Paths $paths)
    {
        parent::__construct();
        $this->paths = $paths;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Middleware name (e.g., ExampleMiddleware)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $path = $this->paths->app() . '/Middleware/' . $name . '.php';

        if (file_exists($path)) {
            $output->writeln('<error>Middleware already exists: ' . $name . '</error>');
            return Command::FAILURE;
        }

        $stub = $this->getStub($name);
        file_put_contents($path, $stub);
        $output->writeln('<info>Middleware created: ' . $path . '</info>');
        return Command::SUCCESS;
    }

    private function getStub(string $name): string
    {
        return <<<PHP
<?php
namespace App\\Middleware;

use Reactor\\Attributes\\Middleware;
use Reactor\\Attributes\\OnUpdate;
use Reactor\\Enums\\MiddlewareMode;
use Reactor\\Core\\App;
use Reactor\\Contracts\\LoggerInterface;
use Reactor\\Contracts\\MiddlewareInterface;

#[Middleware(priority: 50, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('message')]
class {$name} implements MiddlewareInterface
{
    protected App \$app;
    protected LoggerInterface \$logger;

    public function __construct(App \$app, LoggerInterface \$logger)
    {
        \$this->app = \$app;
        \$this->logger = \$logger;
    }

    public function handle(array \$update): bool
    {
        \$fromId = \$update['message']['from']['id'] ?? null;
        if (!\$fromId) {
            return false;
        }

        return false;
    }
}
PHP;
    }
}
