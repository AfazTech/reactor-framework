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
use Reactor\Console\Commands\ScheduleRunCommand;
use Reactor\Console\Commands\PackageDiscoverCommand;
use Reactor\Console\Commands\VendorPublishCommand;
use Reactor\Console\Commands\BotRestartCommand;
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
        $app->add($this->container->get(MigrateCommand::class));
        $app->add($this->container->get(MigrateRollbackCommand::class));
        $app->add($this->container->get(MigrateResetCommand::class));
        $app->add($this->container->get(MigrateRefreshCommand::class));
        $app->add($this->container->get(MigrateMakeCommand::class));
        $app->add($this->container->get(MigrateStatusCommand::class));
        $app->add($this->container->get(SeedCommand::class));
        $app->add($this->container->get(MakeHandlerCommand::class));
        $app->add($this->container->get(MakeMiddlewareCommand::class));
        $app->add($this->container->get(MakeStepCommand::class));
        $app->add($this->container->get(QueueWorkCommand::class));
        $app->add($this->container->get(ScheduleRunCommand::class));
        $app->add($this->container->get(PackageDiscoverCommand::class));
        $app->add($this->container->get(VendorPublishCommand::class));
        $app->add($this->container->get(BotRestartCommand::class));
    }
}
