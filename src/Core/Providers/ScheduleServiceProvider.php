<?php
namespace Reactor\Core\Providers;

use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;
use Reactor\Core\Schedule\Schedule;

/**
 * Service provider that registers the scheduler.
 */
class ScheduleServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(Schedule::class, function () {
            return new Schedule();
        });
    }

    public function boot(Container $container): void
    {
    }
}
