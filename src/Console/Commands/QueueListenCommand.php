<?php
namespace Reactor\Console\Commands;

use Reactor\Console\Support\EntryPointResolver;
use Reactor\Core\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Process jobs by spawning a fresh queue:work subprocess on every iteration.
 *
 * Unlike queue:work, which keeps a single long-lived process, this
 * command reloads the application code between iterations. It is meant
 * for development environments where code changes should be reflected
 * immediately without manually restarting the worker.
 *
 * Requires the exec() function. Use queue:work in production.
 *
 * The entry point path is resolved through EntryPointResolver, which
 * avoids hardcoding a filename and works with any project layout.
 */
#[AsCommand(name: 'queue:listen', description: 'Process jobs, reloading the application on every iteration')]
class QueueListenCommand extends Command
{
    public function __construct(
        private Paths $paths,
        private EntryPointResolver $entryPointResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'queue',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated queue names, in priority order',
                'default'
            )
            ->addOption(
                'tries',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum attempts per job',
                3
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seconds a single job may run before timing out',
                60
            )
            ->addOption(
                'sleep',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seconds to sleep between iterations when the queue is empty',
                1
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!function_exists('exec')) {
            $output->writeln(
                '<error>queue:listen requires the exec() function. '
                . 'Use queue:work in this environment instead.</error>'
            );
            return Command::FAILURE;
        }

        $queue   = (string) $input->getOption('queue');
        $tries   = (int) $input->getOption('tries');
        $timeout = (int) $input->getOption('timeout');
        $sleep   = max(1, (int) $input->getOption('sleep'));

        $script = $this->resolveScriptPath();
        if ($script === null) {
            $output->writeln(
                '<error>Unable to locate the application CLI entry point. '
                . 'Ensure a "reactor" file exists at the project root, or set '
                . "Container::instance('entry_point', __FILE__) in your bootstrap.</error>"
            );
            return Command::FAILURE;
        }

        $output->writeln('<info>Queue listener started. Press Ctrl+C to stop.</info>');
        $output->writeln("Entry point: {$script}");

        while (true) {
            $cmd = sprintf(
                '%s %s queue:work --stop-when-empty --queue=%s --tries=%d --timeout=%d',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg($queue),
                $tries,
                $timeout
            );

            exec($cmd, $ignored, $exitCode);

            if ($exitCode !== 0) {
                $output->writeln(
                    "<comment>Child process exited with code {$exitCode}; continuing.</comment>"
                );
            }

            sleep($sleep);
        }
    }

    private function resolveScriptPath(): ?string
    {
        return $this->entryPointResolver->resolve();
    }
}
