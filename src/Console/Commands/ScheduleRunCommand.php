<?php
namespace Reactor\Console\Commands;

use Reactor\Console\ScheduleKernel;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Core\Schedule\Schedule;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs scheduled tasks that are due right now.
 *
 * Intended to be invoked every minute via cron:
 *
 *   * * * * * php /path/to/reactor.php schedule:run
 */
#[AsCommand(name: 'schedule:run', description: 'Run due scheduled tasks')]
class ScheduleRunCommand extends Command
{
    private Container $container;
    private LoggerInterface $logger;
    private Config $config;

    public function __construct(Container $container, LoggerInterface $logger, Config $config)
    {
        parent::__construct();
        $this->container = $container;
        $this->logger = $logger;
        $this->config = $config;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kernelClass = $this->config->get('app.schedule_kernel', ScheduleKernel::class);

        if (!is_string($kernelClass) || !class_exists($kernelClass)) {
            $output->writeln("<error>Schedule kernel class not found: {$kernelClass}</error>");
            return Command::FAILURE;
        }

        $kernel = new $kernelClass();
        if (!$kernel instanceof ScheduleKernel) {
            $output->writeln(
                '<error>Schedule kernel must extend ' . ScheduleKernel::class . '</error>'
            );
            return Command::FAILURE;
        }

        /** @var Schedule $schedule */
        $schedule = $this->container->get(Schedule::class);
        $kernel->schedule($schedule);

        $schedule->runDueEvents($this->container, $this->logger);

        $output->writeln('<info>Schedule run completed.</info>');
        return Command::SUCCESS;
    }
}
