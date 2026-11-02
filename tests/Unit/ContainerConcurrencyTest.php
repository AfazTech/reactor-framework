<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Container;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

class ConcurrentDependency {}
class ConcurrentConsumer { public function __construct(public ConcurrentDependency $dependency) {} }

class ContainerConcurrencyTest extends TestCase
{
    public function testUnrelatedFiberResolutionsDoNotLookCircular(): void
    {
        $c = new Container();
        $c->bind(ConcurrentDependency::class, function () { delay(0.01); return new ConcurrentDependency(); });
        $objects = await([async(fn () => $c->get(ConcurrentConsumer::class)), async(fn () => $c->get(ConcurrentConsumer::class))]);
        self::assertInstanceOf(ConcurrentConsumer::class, $objects[0]);
        self::assertNotSame($objects[0], $objects[1]);
    }
    public function testSingletonConstructionIsSharedAndFailuresCanBeRetried(): void
    {
        $c = new Container(); $created = 0;
        $c->singleton('service', function () use (&$created) { $created++; delay(0.01); return new \stdClass(); });
        $values = await([async(fn () => $c->get('service')), async(fn () => $c->get('service'))]);
        self::assertSame(1, $created); self::assertSame($values[0], $values[1]);
        $attempt = 0;
        $c->singleton('failed', function () use (&$attempt) { delay(0.01); if (++$attempt === 1) { throw new \RuntimeException('test'); } return new \stdClass(); });
        [$errors] = awaitAll([async(fn () => $c->get('failed')), async(fn () => $c->get('failed'))]);
        self::assertCount(2, $errors); self::assertSame(1, $attempt);
        self::assertInstanceOf(\stdClass::class, $c->get('failed'));
    }
    public function testScopedObjectsAreIndependentAcrossFibers(): void
    {
        $c = new Container(); $c->scoped('service', fn () => new \stdClass());
        $objects = await([async(function () use ($c) { $v = $c->get('service'); delay(0.01); self::assertSame($v, $c->get('service')); return $v; }),
            async(fn () => $c->get('service'))]);
        self::assertNotSame($objects[0], $objects[1]);
    }
    public function testConcurrentSingletonCyclesFailRatherThanDeadlock(): void
    {
        $c = new Container();
        $c->singleton('a', function () use ($c) { delay(0.01); return $c->get('b'); });
        $c->singleton('b', function () use ($c) { delay(0.01); return $c->get('a'); });
        [$errors] = awaitAll([async(fn () => $c->get('a')), async(fn () => $c->get('b'))], new \Amp\TimeoutCancellation(1));
        self::assertCount(2, $errors);
        foreach ($errors as $error) { self::assertInstanceOf(\Reactor\Exceptions\ContainerResolutionException::class, $error); }
    }
}
