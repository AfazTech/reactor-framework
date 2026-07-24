<?php
namespace Reactor\Console;

use Reactor\Core\Schedule\Schedule;

/**
 * Schedule kernel for defining scheduled tasks.
 *
 * Extend this class in your application to register cron jobs.
 */
class ScheduleKernel
{
    /**
     * Define the schedule.
     *
     * @param Schedule $schedule The schedule instance.
     */
    public function schedule(Schedule $schedule): void
    {
        // Override this method to add scheduled tasks.
    }
}
