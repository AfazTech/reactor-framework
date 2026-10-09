<?php
namespace Reactor\Console;

use Symfony\Component\Console\Application;
use Reactor\Console\Commands\MigrateCommand;
use Reactor\Console\Commands\MigrateRollbackCommand;
use Reactor\Console\Commands\MigrateResetCommand;
use Reactor\Console\Commands\MigrateRefreshCommand;
use Reactor\Console\Commands\MigrateMakeCommand;
use Reactor\Console\Commands\MigrateStatusCommand;
use Reactor\Console\Commands\SeedCommand;
use Reactor\Console\Commands\MakeHandlerCommand;
use Reactor\Console\Commands\MakeMiddlewareCommand;
use Reactor\Console\Commands\MakeStepCommand;
use Reactor\Console\Commands\QueueWorkCommand;
use Reactor\Console\Commands\QueueListenCommand;
use Reactor\Console\Commands\QueueRestartCommand;
use Reactor\Console\Commands\QueueFailedCommand;
use Reactor\Console\Commands\QueueRetryCommand;
use Reactor\Console\Commands\QueueForgetCommand;
use Reactor\Console\Commands\QueueFlushCommand;
use Reactor\Console\Commands\QueueMonitorCommand;
use Reactor\Console\Commands\QueueFailedTableCommand;
use Reactor\Console\Commands\QueuePruneFailedCommand;
use Reactor\Console\Commands\ScheduleRunCommand;
use Reactor\Console\Commands\PackageDiscoverCommand;
use Reactor\Console\Commands\VendorPublishCommand;
use Reactor\Console\Commands\StartCommand;
use Reactor\Console\Commands\StopCommand;
use Reactor\Console\Commands\RestartCommand;
use Reactor\Core\Container;

class Kernel
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function registerCommands(Application $app): void
    {
        // Migrations.
        $app->add($this->container->get(MigrateCommand::class));
        $app->add($this->container->get(MigrateRollbackCommand::class));
        $app->add($this->container->get(MigrateResetCommand::class));
        $app->add($this->container->get(MigrateRefreshCommand::class));
        $app->add($this->container->get(MigrateMakeCommand::class));
        $app->add($this->container->get(MigrateStatusCommand::class));

        // Seeding and code generation.
        $app->add($this->container->get(SeedCommand::class));
        $app->add($this->container->get(MakeHandlerCommand::class));
        $app->add($this->container->get(MakeMiddlewareCommand::class));
        $app->add($this->container->get(MakeStepCommand::class));

        // Queue.
        $app->add($this->container->get(QueueWorkCommand::class));
        $app->add($this->container->get(QueueListenCommand::class));
        $app->add($this->container->get(QueueRestartCommand::class));
        $app->add($this->container->get(QueueFailedCommand::class));
        $app->add($this->container->get(QueueRetryCommand::class));
        $app->add($this->container->get(QueueForgetCommand::class));
        $app->add($this->container->get(QueueFlushCommand::class));
        $app->add($this->container->get(QueueMonitorCommand::class));
        $app->add($this->container->get(QueueFailedTableCommand::class));
        $app->add($this->container->get(QueuePruneFailedCommand::class));

        // Scheduler and packages.
        $app->add($this->container->get(ScheduleRunCommand::class));
        $app->add($this->container->get(PackageDiscoverCommand::class));
        $app->add($this->container->get(VendorPublishCommand::class));

        // Bot lifecycle.
        $app->add($this->container->get(StartCommand::class));
        $app->add($this->container->get(StopCommand::class));
        $app->add($this->container->get(RestartCommand::class));
    }
}
