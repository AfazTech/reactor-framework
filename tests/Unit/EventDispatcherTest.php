<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\EventDispatcher;

class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    /** @test */
    public function it_registers_and_dispatches_listeners()
    {
        $called = false;
        $this->dispatcher->on('test.event', function () use (&$called) {
            $called = true;
        });

        $this->dispatcher->dispatch('test.event');
        $this->assertTrue($called);
    }

    /** @test */
    public function it_passes_arguments_to_listeners()
    {
        $result = null;
        $this->dispatcher->on('test.event', function ($arg1, $arg2) use (&$result) {
            $result = $arg1 . ' ' . $arg2;
        });

        $this->dispatcher->dispatch('test.event', 'hello', 'world');
        $this->assertEquals('hello world', $result);
    }

    /** @test */
    public function it_can_unregister_listeners()
    {
        $called = false;
        $listener = function () use (&$called) {
            $called = true;
        };

        $this->dispatcher->on('test.event', $listener);
        $this->dispatcher->off('test.event', $listener);

        $this->dispatcher->dispatch('test.event');
        $this->assertFalse($called);
    }

    /** @test */
    public function it_handles_multiple_listeners_for_same_event()
    {
        $counter = 0;
        $this->dispatcher->on('test.event', function () use (&$counter) { $counter++; });
        $this->dispatcher->on('test.event', function () use (&$counter) { $counter++; });

        $this->dispatcher->dispatch('test.event');
        $this->assertEquals(2, $counter);
    }

    /** @test */
    public function it_does_nothing_when_no_listeners_registered()
    {
        // No exception should be thrown
        $this->dispatcher->dispatch('non.existent.event');
        $this->assertTrue(true);
    }

    /** @test */
    public function it_executes_listeners_in_registration_order()
    {
        $order = [];
        $this->dispatcher->on('test.event', function () use (&$order) { $order[] = 'first'; });
        $this->dispatcher->on('test.event', function () use (&$order) { $order[] = 'second'; });

        $this->dispatcher->dispatch('test.event');
        $this->assertEquals(['first', 'second'], $order);
    }
}
