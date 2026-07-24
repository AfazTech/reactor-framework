<?php

namespace Tests\Stubs;

interface TestInterface {}

class TestClass implements TestInterface {}

class ClassWithDependency
{
    public TestInterface $dep;
    public function __construct(TestInterface $dep)
    {
        $this->dep = $dep;
    }
}

class ClassWithCircularA
{
    public function __construct(ClassWithCircularB $b) {}
}

class ClassWithCircularB
{
    public function __construct(ClassWithCircularA $a) {}
}

class ClassWithDefaultValue
{
    public $param;
    public function __construct($param = 'default')
    {
        $this->param = $param;
    }
}

class ClassWithoutConstructor {}

class ClassWithScalarDependency
{
    public function __construct(string $name, int $age) {}
}

// For contextual binding tests
interface LoggerInterface {}
class FileLogger implements LoggerInterface {}
class DatabaseLogger implements LoggerInterface {}

class UserController
{
    public LoggerInterface $logger;
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}

// For testing non-instantiable class (abstract)
abstract class AbstractClass {}
