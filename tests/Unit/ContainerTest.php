<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Container;
use Reactor\Exceptions\ContainerResolutionException;
use Tests\Stubs\{
    TestInterface,
    TestClass,
    ClassWithDependency,
    ClassWithCircularA,
    ClassWithCircularB,
    ClassWithDefaultValue,
    ClassWithoutConstructor,
    ClassWithScalarDependency,
    LoggerInterface,
    FileLogger,
    DatabaseLogger,
    UserController,
    AbstractClass
};

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    /** @test */
    public function it_registers_and_resolves_singleton_binding()
    {
        $this->container->singleton(TestInterface::class, function () {
            return new TestClass();
        });

        $instance1 = $this->container->get(TestInterface::class);
        $instance2 = $this->container->get(TestInterface::class);

        $this->assertInstanceOf(TestClass::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /** @test */
    public function it_registers_and_resolves_transient_binding()
    {
        $this->container->bind(TestInterface::class, function () {
            return new TestClass();
        });

        $instance1 = $this->container->get(TestInterface::class);
        $instance2 = $this->container->get(TestInterface::class);

        $this->assertInstanceOf(TestClass::class, $instance1);
        $this->assertNotSame($instance1, $instance2);
    }

    /** @test */
    public function it_auto_resolves_class_without_constructor()
    {
        $instance = $this->container->get(ClassWithoutConstructor::class);
        $this->assertInstanceOf(ClassWithoutConstructor::class, $instance);
    }

    /** @test */
    public function it_auto_resolves_class_with_default_value_parameters()
    {
        $instance = $this->container->get(ClassWithDefaultValue::class);
        $this->assertInstanceOf(ClassWithDefaultValue::class, $instance);
        $this->assertEquals('default', $instance->param);
    }

    /** @test */
    public function it_auto_resolves_class_with_dependencies()
    {
        // Bind the interface so it can be resolved
        $this->container->singleton(TestInterface::class, function () {
            return new TestClass();
        });

        $instance = $this->container->get(ClassWithDependency::class);
        $this->assertInstanceOf(ClassWithDependency::class, $instance);
        $this->assertInstanceOf(TestClass::class, $instance->dep);
    }

    /** @test */
    public function it_throws_exception_when_interface_has_no_binding()
    {
        $this->expectException(ContainerResolutionException::class);
        $this->expectExceptionMessageMatches('/Interface .* has no binding registered/');

        $this->container->get(TestInterface::class);
    }

    /** @test */
    public function it_throws_exception_when_class_is_not_instantiable()
    {
        $this->expectException(ContainerResolutionException::class);
        $this->expectExceptionMessageMatches('/is not instantiable/');

        $this->container->get(AbstractClass::class);
    }

    /** @test */
    public function it_detects_circular_dependency()
    {
        $this->expectException(ContainerResolutionException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->container->get(ClassWithCircularA::class);
    }

    /** @test */
    public function it_throws_exception_for_scalar_dependencies_without_default_value()
    {
        $this->expectException(ContainerResolutionException::class);
        $this->expectExceptionMessage('Cannot resolve parameter $name');

        $this->container->get(ClassWithScalarDependency::class);
    }

    /** @test */
    public function it_applies_contextual_binding()
    {
        // Bind interface globally to FileLogger
        $this->container->singleton(LoggerInterface::class, function () {
            return new FileLogger();
        });

        // But for UserController, use DatabaseLogger
        $this->container
            ->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);

        $controller = $this->container->get(UserController::class);
        $this->assertInstanceOf(DatabaseLogger::class, $controller->logger);
    }

    /** @test */
    public function it_supports_tagging_and_resolving_tagged_services()
    {
        $this->container->singleton('service.a', function () {
            return new \stdClass();
        });
        $this->container->singleton('service.b', function () {
            return new \stdClass();
        });
        $this->container->singleton('service.c', function () {
            return new \stdClass();
        });

        $this->container->tag(['service.a', 'service.b'], 'group1');
        $this->container->tag(['service.c'], 'group2');

        $group1 = $this->container->tagged('group1');
        $this->assertCount(2, $group1);
        $this->assertInstanceOf(\stdClass::class, $group1[0]);
        $this->assertInstanceOf(\stdClass::class, $group1[1]);

        $group2 = $this->container->tagged('group2');
        $this->assertCount(1, $group2);

        $nonExistent = $this->container->tagged('non-existent');
        $this->assertCount(0, $nonExistent);
    }

    /** @test */
    public function it_checks_if_binding_exists()
    {
        $this->assertFalse($this->container->has('some.abstract'));

        $this->container->singleton('some.abstract', function () {
            return new \stdClass();
        });
        $this->assertTrue($this->container->has('some.abstract'));

        // Resolve it so it becomes an instance
        $this->container->get('some.abstract');
        $this->assertTrue($this->container->has('some.abstract'));
    }

    /** @test */
    public function it_returns_same_instance_for_singleton_even_if_resolved_multiple_times()
    {
        $this->container->singleton('shared', function () {
            return new \stdClass();
        });

        $a = $this->container->get('shared');
        $b = $this->container->get('shared');
        $this->assertSame($a, $b);
    }

    /** @test */
    public function it_can_resolve_contextual_binding_with_a_callable()
    {
        $this->container
            ->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(function () {
                return new FileLogger();
            });

        $controller = $this->container->get(UserController::class);
        $this->assertInstanceOf(FileLogger::class, $controller->logger);
    }

    /** @test */
    public function it_can_resolve_contextual_binding_with_a_class_string()
    {
        $this->container->singleton(LoggerInterface::class, function () {
            return new FileLogger();
        });

        $this->container
            ->when(UserController::class)
            ->needs(LoggerInterface::class)
            ->give(DatabaseLogger::class);

        $controller = $this->container->get(UserController::class);
        $this->assertInstanceOf(DatabaseLogger::class, $controller->logger);
    }
}
