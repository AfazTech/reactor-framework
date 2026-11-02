<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\UpdateSequencer;
use Reactor\Testing\UpdateFactory;

class UpdateSequencerTest extends TestCase
{
    public function testSameUserIsOrderedWhileOtherUsersCanProgress(): void
    {
        $sequencer = new UpdateSequencer(); $events = [];
        $first = \Amp\async(function () use ($sequencer, &$events) {
            return $sequencer->run(UpdateFactory::message(1, 'a'), function () use (&$events) {
                $events[] = 'first:start'; \Amp\delay(0.03); $events[] = 'first:end';
            });
        });
        $second = \Amp\async(function () use ($sequencer, &$events) {
            return $sequencer->run(UpdateFactory::message(1, 'b'), function () use (&$events) { $events[] = 'second'; });
        });
        $other = \Amp\async(function () use ($sequencer, &$events) {
            return $sequencer->run(UpdateFactory::message(2, 'c'), function () use (&$events) { $events[] = 'other'; });
        });
        \Amp\Future\await([$first, $second, $other]);
        self::assertSame(['first:start', 'other', 'first:end', 'second'], $events);
    }
    public function testReentrantUseAndErrorsReleaseTheKey(): void
    {
        $sequencer = new UpdateSequencer(); $update = UpdateFactory::message(1, 'test');
        self::assertSame('nested', $sequencer->run($update, fn () => $sequencer->run($update, fn () => 'nested')));
        try { $sequencer->run($update, fn () => throw new \RuntimeException('test')); } catch (\RuntimeException) {}
        self::assertSame('next', $sequencer->run($update, fn () => 'next'));
    }
}
