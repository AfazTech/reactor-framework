<?php

namespace Reactor\Contracts;

interface QueuedEventListenerInterface
{
    /** Arguments are JSON-compatible data, not container services or exception objects. */
    public function handle(string $event, array $arguments): void;
}
