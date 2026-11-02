<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Attributes\QueuedListener;
use Reactor\Contracts\QueuedEventListenerInterface;
use Reactor\Core\Container;
use Reactor\Core\EventDispatcher;
use Reactor\Listeners\InvokeQueuedListener;

#[QueuedListener('test', queue: 'mail')]
class ExampleQueuedListener implements QueuedEventListenerInterface
{
    public static array $received = [];
    public function handle(string $event, array $arguments): void { self::$received[] = [$event, $arguments]; }
}

class QueuedListenerTest extends TestCase
{
    public function testListenerRunsOnlyWhenItsJobIsExecuted(): void
    {
        ExampleQueuedListener::$received = []; $jobs = [];
        $events = new EventDispatcher(function ($class, $event, $args, $queue) use (&$jobs) { $jobs[] = compact('class', 'event', 'args', 'queue'); });
        $events->subscribe(ExampleQueuedListener::class);
        $events->dispatch('test', 10, ['name' => 'test']);
        self::assertSame([], ExampleQueuedListener::$received);
        self::assertSame('mail', $jobs[0]['queue']);
        (new InvokeQueuedListener(new Container()))->handle([
            'listener' => $jobs[0]['class'], 'event' => $jobs[0]['event'], 'arguments' => $jobs[0]['args'],
        ]);
        self::assertSame([['test', [10, ['name' => 'test']]]], ExampleQueuedListener::$received);
    }
    public function testLocalPropagationStopPreventsQueueing(): void
    {
        $events = new EventDispatcher(fn () => self::fail('Must not enqueue'));
        $events->onQueued('test', ExampleQueuedListener::class); $events->on('test', fn () => false);
        self::assertTrue($events->dispatch('test', 1));
    }
    public function testObjectsCannotBeSilentlySerializedIntoQueuedEvents(): void
    {
        $events = new EventDispatcher(fn () => self::fail('Invalid arguments must not enqueue'));
        $events->onQueued('test', ExampleQueuedListener::class);
        $this->expectException(\InvalidArgumentException::class); $events->dispatch('test', new \stdClass());
    }
}
