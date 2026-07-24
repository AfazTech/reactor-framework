<?php

namespace Reactor\Core;

/**
 * Simple synchronous event dispatcher.
 */
class EventDispatcher
{
    /**
     * @var array<string, array<callable>>  Registered listeners per event.
     */
    private array $listeners = [];

    /**
     * Register a listener for an event.
     *
     * @param string   $event    The event name.
     * @param callable $listener The listener callback.
     */
    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Unregister a listener.
     *
     * @param string   $event    The event name.
     * @param callable $listener The listener to remove.
     */
    public function off(string $event, callable $listener): void
    {
        if (isset($this->listeners[$event])) {
            $this->listeners[$event] = array_filter(
                $this->listeners[$event],
                fn($l) => $l !== $listener
            );
        }
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * @param string $event The event name.
     * @param mixed  ...$args Arguments to pass to listeners.
     */
    public function dispatch(string $event, ...$args): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$args);
        }
    }
}
