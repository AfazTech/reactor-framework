<?php
namespace Reactor\Core\Schedule;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Container;

/**
 * Schedule manager that holds and runs scheduled events.
 */
class Schedule
{
    private array $events = [];

    public function call(callable $callback): Event
    {
        $event = new Event($callback);
        $this->events[] = $event;
        return $event;
    }

    public function job(string $jobClass): Event
    {
        $event = new Event(null, $jobClass);
        $this->events[] = $event;
        return $event;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Run all events that are due at the given time.
     *
     * @param Container            $container
     * @param LoggerInterface      $logger
     * @param \DateTimeImmutable|null $time
     */
    public function runDueEvents(Container $container, LoggerInterface $logger, ?\DateTimeImmutable $time = null): void
    {
        $time = $time ?? new \DateTimeImmutable('now');

        foreach ($this->events as $event) {
            if ($event->isDue($time)) {
                $event->run($container, $logger);
            }
        }
    }
}
