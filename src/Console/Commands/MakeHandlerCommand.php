<?php
namespace Reactor\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Reactor\Core\Paths;

#[AsCommand(name: 'make:handler', description: 'Create a new handler class')]
class MakeHandlerCommand extends Command
{
    private Paths $paths;

    public function __construct(Paths $paths)
    {
        parent::__construct();
        $this->paths = $paths;
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Handler name (e.g., ExampleHandler)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $path = $this->paths->app() . '/Handlers/' . $name . '.php';

        if (file_exists($path)) {
            $output->writeln('<error>Handler already exists: ' . $name . '</error>');
            return Command::FAILURE;
        }

        $stub = $this->getStub($name);
        file_put_contents($path, $stub);
        $output->writeln('<info>Handler created: ' . $path . '</info>');
        return Command::SUCCESS;
    }

    private function getStub(string $name): string
    {
        $textName = strtolower($name);
        return <<<PHP
<?php
namespace App\\Handlers;

use Reactor\\Attributes\\Text;
use Reactor\\Attributes\\OnUpdate;
use Reactor\\Attributes\\Group;
use App\\Handlers\\BaseHandler;

#[Group('private')]
#[Text(name: '{$textName}', priority: 5)]
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
        \$text = \$this->language->get('welcome', \$lang);
        \$keyboard = (new \\App\\Keyboard(\$this->language))->mainMenu(\$lang);
        \$this->reply(\$text, \$keyboard);
    }
}
PHP;
    }
}
