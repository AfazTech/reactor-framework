<?php

namespace Reactor\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class QueuedListener
{
    public function __construct(public string $event, public string $queue = 'default')
    {
        if ($event === '' || $queue === '') { throw new \InvalidArgumentException('Event and queue cannot be empty.'); }
    }
}
