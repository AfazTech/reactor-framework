<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Core\Paths;
use Reactor\Database\Migrations\MigrationRegistrar;
use Reactor\Database\Migrations\MigrationRepository;
use Reactor\Database\Migrations\Migrator;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Regression tests for lazy migration infrastructure.
 *
 * Resolving Migrator (or constructing MigrationRepository directly) must
 * not touch the database. Only explicit calls to ensureReady() / run()
 * / rollback() / reset() / refresh() / status() are allowed to trigger
 * schema work. This keeps regular request lifecycles free of migration
 * side effects.
 */
class MigratorLazyTest extends TestCase
{
    private DatabaseManagerInterface&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private Config&MockObject $config;
    private Paths&MockObject $paths;
    private Container&MockObject $container;
    private MigrationRegistrar $registrar;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->paths = $this->createMock(Paths::class);
        $this->container = $this->createMock(Container::class);
        $this->registrar = new MigrationRegistrar();

        $this->config->method('get')->willReturnCallback(function ($key, $default = null) {
            return match ($key) {
                'database.migrations.namespace' => 'App\\Migrations',
                'app.name'                      => 'reactor',
                default                         => $default,
            };
        });

        $this->paths->method('database')->willReturn('/tmp/reactor_test/database');
        $this->paths->method('storage')->willReturn('/tmp/reactor_test/storage');
    }

    /** @test */
    public function constructing_migration_repository_does_not_touch_the_database(): void
    {
        $this->db->expects($this->never())->method('schema');
        $this->db->expects($this->never())->method('table');

        new MigrationRepository($this->db, $this->logger, $this->registrar, $this->container);
    }

    /** @test */
    public function constructing_migrator_does_not_touch_the_database(): void
    {
        $this->db->expects($this->never())->method('schema');
        $this->db->expects($this->never())->method('table');

        new Migrator(
            $this->logger,
            $this->config,
            $this->db,
            $this->paths,
            $this->container,
            $this->registrar
        );
    }

    /** @test */
    public function ensure_ready_creates_the_tracking_table(): void
    {
        $schema = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['hasTable', 'create'])
            ->getMock();
        $schema->expects($this->once())->method('hasTable')->with('migrations')->willReturn(false);
        $schema->expects($this->once())->method('create');

        $this->db->method('schema')->willReturn($schema);

        $migrator = new Migrator(
            $this->logger,
            $this->config,
            $this->db,
            $this->paths,
            $this->container,
            $this->registrar
        );

        $migrator->ensureReady();
    }

    /** @test */
    public function ensure_ready_is_idempotent_within_the_same_instance(): void
    {
        $schema = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['hasTable', 'create'])
            ->getMock();
        // hasTable is called only once per instance thanks to the flag.
        $schema->expects($this->once())->method('hasTable')->willReturn(true);
        $schema->expects($this->never())->method('create');

        $this->db->method('schema')->willReturn($schema);

        $migrator = new Migrator(
            $this->logger,
            $this->config,
            $this->db,
            $this->paths,
            $this->container,
            $this->registrar
        );

        $migrator->ensureReady();
        $migrator->ensureReady();
        $migrator->ensureReady();
    }
}
