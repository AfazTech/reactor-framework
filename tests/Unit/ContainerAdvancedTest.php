<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Container;
use Tests\Stubs\TestInterface;
use Tests\Stubs\TestClass;

/**
 * Covers the public container API added on top of the basic bind /
 * singleton / contextual / tagging features:
 *
 *   - instance()
 *   - class-string concrete arguments
 *   - factory() as an alias of bind()
 *   - scoped() lifecycle and forgetScopedInstances()
 *   - alias() with cycle detection
 *   - forgetInstance()
 */
class ContainerAdvancedTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    /** @test */
    public function it_registers_a_direct_instance(): void
    {
        $obj = new \stdClass();
        $this->container->instance('obj', $obj);

        $this->assertTrue($this->container->has('obj'));
        $this->assertSame($obj, $this->container->get('obj'));
        $this->assertSame($obj, $this->container->get('obj'));
    }

    /** @test */
    public function instance_takes_precedence_over_bindings(): void
    {
        $this->container->singleton('svc', fn() => new \stdClass());
        $direct = new \stdClass();
        $this->container->instance('svc', $direct);

        $this->assertSame($direct, $this->container->get('svc'));
    }

    /** @test */
    public function it_forgets_instances(): void
    {
        $this->container->instance('obj', new \stdClass());
        $this->assertTrue($this->container->has('obj'));

        $this->container->forgetInstance('obj');
        $this->assertFalse($this->container->has('obj'));
    }

    /** @test */
    public function it_forgets_a_resolved_singleton(): void
    {
        $counter = 0;
        $this->container->singleton('svc', function () use (&$counter) {
            $counter++;
            return new \stdClass();
        });

        $first = $this->container->get('svc');
        $this->container->forgetInstance('svc');
        $second = $this->container->get('svc');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $counter);
    }

    /** @test */
    public function it_binds_transient_to_a_class_string(): void
    {
        $this->container->bind(TestInterface::class, TestClass::class);

        $a = $this->container->get(TestInterface::class);
        $b = $this->container->get(TestInterface::class);

        $this->assertInstanceOf(TestClass::class, $a);
        $this->assertInstanceOf(TestClass::class, $b);
        $this->assertNotSame($a, $b);
    }

    /** @test */
    public function it_binds_singleton_to_a_class_string(): void
    {
        $this->container->singleton(TestInterface::class, TestClass::class);

        $a = $this->container->get(TestInterface::class);
        $b = $this->container->get(TestInterface::class);

        $this->assertInstanceOf(TestClass::class, $a);
        $this->assertSame($a, $b);
    }

    /** @test */
    public function factory_is_an_alias_of_bind(): void
    {
        $counter = 0;
        $this->container->factory('svc', function () use (&$counter) {
            $counter++;
            return new \stdClass();
        });

        $a = $this->container->get('svc');
        $b = $this->container->get('svc');

        $this->assertNotSame($a, $b);
        $this->assertSame(2, $counter);
    }

    /** @test */
    public function it_registers_and_resolves_a_scoped_binding(): void
    {
        $counter = 0;
        $this->container->scoped('scoped_svc', function () use (&$counter) {
            $counter++;
            return new \stdClass();
        });

        $a = $this->container->get('scoped_svc');
        $b = $this->container->get('scoped_svc');

        $this->assertSame($a, $b);
        $this->assertSame(1, $counter);
    }

    /** @test */
    public function it_resets_scoped_instances_via_forget_scoped_instances(): void
    {
        $counter = 0;
        $this->container->scoped('scoped_svc', function () use (&$counter) {
            $counter++;
            return new \stdClass();
        });

        $a = $this->container->get('scoped_svc');
        $this->container->forgetScopedInstances();
        $b = $this->container->get('scoped_svc');

        $this->assertNotSame($a, $b);
        $this->assertSame(2, $counter);
    }

    /** @test */
    public function forget_scoped_instances_does_not_affect_singletons(): void
    {
        $counter = 0;
        $this->container->singleton('singleton_svc', function () use (&$counter) {
            $counter++;
            return new \stdClass();
        });

        $a = $this->container->get('singleton_svc');
        $this->container->forgetScopedInstances();
        $b = $this->container->get('singleton_svc');

        $this->assertSame($a, $b);
        $this->assertSame(1, $counter);
    }

    /** @test */
    public function it_resolves_a_simple_alias(): void
    {
        $this->container->singleton('original', fn() => new \stdClass());
        $this->container->alias('aliased', 'original');

        $a = $this->container->get('aliased');
        $b = $this->container->get('original');

        $this->assertSame($a, $b);
    }

    /** @test */
    public function it_resolves_an_alias_chain(): void
    {
        $this->container->singleton('real', fn() => new \stdClass());
        $this->container->alias('mid', 'real');
        $this->container->alias('top', 'mid');

        $this->assertSame($this->container->get('real'), $this->container->get('top'));
    }

    /** @test */
    public function has_recognizes_aliases(): void
    {
        $this->container->singleton('original', fn() => new \stdClass());
        $this->container->alias('aliased', 'original');

        $this->assertTrue($this->container->has('aliased'));
    }

    /** @test */
    public function it_prevents_self_aliasing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->container->alias('foo', 'foo');
    }

    /** @test */
    public function it_prevents_two_step_alias_cycles(): void
    {
        $this->container->alias('a', 'b');

        $this->expectException(\InvalidArgumentException::class);
        $this->container->alias('b', 'a');
    }

    /** @test */
    public function it_prevents_longer_alias_cycles(): void
    {
        $this->container->alias('a', 'b');
        $this->container->alias('b', 'c');
        $this->container->alias('c', 'd');

        $this->expectException(\InvalidArgumentException::class);
        $this->container->alias('d', 'a');
    }
}
