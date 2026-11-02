<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Cache\Drivers\ArrayCache;
use Reactor\Conversation\ConversationManager;
use Reactor\Conversation\Flow;

class ConversationTest extends TestCase
{
    public function testValidationBackCompletionAndPersistenceAcrossManagers(): void
    {
        $cache = new ArrayCache();
        $flow = (new Flow('signup'))->ask('name', 'Name?', fn ($input) => strlen($input) > 1)->ask('age', 'Age?', fn ($v) => ctype_digit($v));
        $manager = new ConversationManager($cache); $manager->define($flow);
        self::assertSame('Name?', $manager->start(1, 'signup')->prompt);
        self::assertSame('invalid', $manager->accept(1, 'x')->status);
        self::assertSame('Age?', $manager->accept(1, 'Alice')->prompt);
        $restored = new ConversationManager($cache); $restored->define($flow);
        self::assertSame('Name?', $restored->back(1)->prompt);
        $restored->accept(1, 'Bob');
        $result = $restored->accept(1, '20');
        self::assertSame('completed', $result->status);
        self::assertSame(['name' => 'Bob', 'age' => '20'], $result->answers);
        self::assertFalse($restored->active(1));
    }
    public function testExpiryCancellationUserIsolationAndAsyncValidators(): void
    {
        $cache = new ArrayCache();
        $manager = new ConversationManager($cache); $manager->define((new Flow('test'))->ask('x', 'X?', fn () => \Amp\Future::complete(true)));
        $manager->start(1, 'test'); $manager->start(2, 'test');
        self::assertSame('completed', $manager->accept(1, 'one')->status);
        self::assertTrue($manager->active(2)); self::assertSame('cancelled', $manager->cancel(2)->status);
        $cache->set('reactor:conversation:default:3', ['flow' => 'test', 'index' => 0, 'answers' => [], 'expires' => time() - 1])->await();
        self::assertSame('inactive', $manager->accept(3, 'expired')->status);
    }
}
