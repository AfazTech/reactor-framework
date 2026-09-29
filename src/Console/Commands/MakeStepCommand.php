<?php
namespace Reactor\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Reactor\Core\Paths;

#[AsCommand(name: 'make:step', description: 'Create a new step handler class')]
class MakeStepCommand extends Command
{
    private Paths $paths;

    public function __construct(Paths $paths)
    {
        parent::__construct();
        $this->paths = $paths;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Step name (e.g., ExampleStep)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $dir = $this->paths->app() . '/Handlers/Steps';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $name . '.php';

        if (file_exists($path)) {
            $output->writeln('<error>Step already exists: ' . $name . '</error>');
            return Command::FAILURE;
        }

        $stub = $this->getStub($name);
        file_put_contents($path, $stub);
        $output->writeln('<info>Step created: ' . $path . '</info>');
        return Command::SUCCESS;
    }

    private function getStub(string $name): string
    {
        $stepName = $this->toStepName($name);
        return <<<PHP
<?php
namespace App\\Handlers\\Steps;

use Reactor\\Attributes\\Step;
use Reactor\\Attributes\\OnUpdate;
use App\\Handlers\\BaseHandler;

#[Step(name: '{$stepName}', nextStep: null, autoClear: true)]
#[OnUpdate('message')]
class {$name} extends BaseHandler
{
    protected function handle(array \$params = []): void
    {
        \$fromId = \$this->getUserId();
        if (!\$fromId) {
            return;
        }
        \$lang = \$this->getUserLanguage();
        \$this->reply('Step handler executed.');
    }
}
PHP;
    }

    /**
     * Convert a class-style name to a snake_case step identifier.
     *
     * "ExampleStep"       => "example"
     * "RegisterUserStep"  => "register_user"
     */
    private function toStepName(string $name): string
    {
        $name = preg_replace('/Step$/', '', $name) ?? $name;
        $snake = preg_replace('/(?<!^)([A-Z])/', '_$1', $name) ?? $name;
        return strtolower($snake);
    }
}
