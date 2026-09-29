<?php
namespace Reactor\Contracts;

/**
 * Contract for scheduled jobs.
 *
 * Classes implementing this interface can be registered in the schedule.
 */
interface ScheduledJobInterface
{
    /**
     * Execute the scheduled job.
     */
    public function handle(): void;
}
