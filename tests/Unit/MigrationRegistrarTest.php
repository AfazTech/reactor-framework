<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Database\Migrations\MigrationRegistrar;
use Reactor\Database\Migrations\MigrationSource;
use Reactor\Contracts\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class MigrationRegistrarTest extends TestCase
{
    private MigrationRegistrar $registrar;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registrar = new MigrationRegistrar($this->logger);
    }

    /** @test */
    public function it_starts_empty(): void
    {
        $this->assertFalse($this->registrar->hasAny());
        $this->assertSame([], $this->registrar->getSources());
    }

    /** @test */
    public function it_registers_a_source(): void
    {
        $result = $this->registrar->add('/app/database/migrations', 'App\\Migrations');

        $this->assertTrue($result);
        $this->assertTrue($this->registrar->hasAny());

        $sources = $this->registrar->getSources();
        $this->assertCount(1, $sources);
        $this->assertInstanceOf(MigrationSource::class, $sources[0]);
        $this->assertSame('/app/database/migrations', $sources[0]->path);
        $this->assertSame('App\\Migrations', $sources[0]->namespace);
    }

    /** @test */
    public function it_is_idempotent_for_the_same_path_and_namespace(): void
    {
        $first = $this->registrar->add('/app/migrations', 'App\\Migrations');
        $second = $this->registrar->add('/app/migrations', 'App\\Migrations');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertCount(1, $this->registrar->getSources());
    }

    /** @test */
    public function it_rejects_a_duplicate_path_with_a_different_namespace(): void
    {
        $this->registrar->add('/app/migrations', 'App\\Migrations');
        $second = $this->registrar->add('/app/migrations', 'Different\\Namespace');

        $this->assertFalse($second);
        $this->assertCount(1, $this->registrar->getSources());
        $this->assertSame('App\\Migrations', $this->registrar->getSources()[0]->namespace);
    }

    /** @test */
    public function it_registers_multiple_sources(): void
    {
        $this->registrar->add('/app/database/migrations', 'App\\Migrations');
        $this->registrar->add('/vendor/acme/database/migrations', 'Acme\\Migrations');

        $sources = $this->registrar->getSources();
        $this->assertCount(2, $sources);
        $this->assertSame('/app/database/migrations', $sources[0]->path);
        $this->assertSame('/vendor/acme/database/migrations', $sources[1]->path);
    }

    /** @test */
    public function it_normalizes_trailing_slash_and_leading_backslash(): void
    {
        $this->registrar->add('/app/migrations/', '\\App\\Migrations\\');

        $sources = $this->registrar->getSources();
        $this->assertSame('/app/migrations', $sources[0]->path);
        $this->assertSame('App\\Migrations', $sources[0]->namespace);
    }

    /** @test */
    public function it_rejects_empty_path_or_namespace(): void
    {
        $this->assertFalse($this->registrar->add('', 'App\\Migrations'));
        $this->assertFalse($this->registrar->add('/some/path', ''));
        $this->assertFalse($this->registrar->hasAny());
    }

    /** @test */
    public function it_works_without_a_logger(): void
    {
        // Standalone usage: no logger is provided.
        $registrar = new MigrationRegistrar();
        $this->assertTrue($registrar->add('/app/migrations', 'App\\Migrations'));
        $this->assertCount(1, $registrar->getSources());
    }
}
