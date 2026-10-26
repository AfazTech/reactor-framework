<?php

namespace Tests\Unit;

use Amp\Future;
use PHPUnit\Framework\TestCase;
use Reactor\Database\Async\CallbackAsyncDatabaseManager;

class CallbackAsyncDatabaseManagerTest extends TestCase
{
    /** @test */
    public function it_delegates_every_call_to_the_provided_closures(): void
    {
        $manager = new CallbackAsyncDatabaseManager(
            driver: 'test',
            fetchAll: fn(string $sql, array $params) => Future::complete([['id' => 1]]),
            fetchOne: fn(string $sql, array $params) => Future::complete(['id' => 1]),
            execute: fn(string $sql, array $params) => Future::complete(3),
            transaction: fn(callable $cb) => Future::complete('tx'),
        );

        $this->assertSame('test', $manager->getDriver());
        $this->assertSame([['id' => 1]], $manager->fetchAll('SELECT 1')->await());
        $this->assertSame(['id' => 1], $manager->fetchOne('SELECT 1')->await());
        $this->assertSame(3, $manager->execute('UPDATE x')->await());
        $this->assertSame('tx', $manager->transaction(fn() => null)->await());
    }
}
