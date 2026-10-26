<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Contracts\AsyncDatabaseManagerInterface;

class AsyncDatabaseManagerContractTest extends TestCase
{
    /** @test */
    public function the_interface_exposes_the_expected_methods(): void
    {
        $reflection = new \ReflectionClass(AsyncDatabaseManagerInterface::class);

        $this->assertTrue($reflection->hasMethod('getDriver'));
        $this->assertTrue($reflection->hasMethod('fetchAll'));
        $this->assertTrue($reflection->hasMethod('fetchOne'));
        $this->assertTrue($reflection->hasMethod('execute'));
        $this->assertTrue($reflection->hasMethod('transaction'));
    }

    /** @test */
    public function fetch_all_returns_a_future(): void
    {
        $method = new \ReflectionMethod(AsyncDatabaseManagerInterface::class, 'fetchAll');

        $this->assertSame('Amp\\Future', (string) $method->getReturnType());
    }

    /** @test */
    public function transaction_returns_a_future(): void
    {
        $method = new \ReflectionMethod(AsyncDatabaseManagerInterface::class, 'transaction');

        $this->assertSame('Amp\\Future', (string) $method->getReturnType());
    }
}
