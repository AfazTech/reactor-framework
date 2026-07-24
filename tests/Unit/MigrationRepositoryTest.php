<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Database\Migrations\MigrationRepository;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Container;
use PHPUnit\Framework\MockObject\MockObject;

class MigrationRepositoryTest extends TestCase
{
    private MigrationRepository $repository;
    private DatabaseManagerInterface&MockObject $dbMock;
    private LoggerInterface&MockObject $loggerMock;
    private Container&MockObject $containerMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(DatabaseManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->containerMock = $this->createMock(Container::class);

        // Create a proper schema mock that has hasTable method
        $schemaMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['hasTable', 'create'])
            ->getMock();
        $schemaMock->method('hasTable')->willReturn(true);

        $this->dbMock->method('schema')->willReturn($schemaMock);

        $this->repository = new MigrationRepository(
            $this->dbMock,
            $this->loggerMock,
            '/fake/path',
            $this->containerMock
        );
    }

    /** @test */
    public function it_converts_migration_filename_to_class_name_correctly()
    {
        $cases = [
            '2026_07_16_000002_create_users_table' => 'CreateUsersTable',
            '2026_07_22_000000_create_jobs_table' => 'CreateJobsTable',
            '2024_01_01_120000_add_index_to_posts' => 'AddIndexToPosts',
            '2023_12_31_235959_remove_old_column' => 'RemoveOldColumn',
            '2022_01_01_000000_just_a_migration' => 'JustAMigration',
        ];

        foreach ($cases as $filename => $expected) {
            $result = $this->repository->classNameFromMigration($filename);
            $this->assertEquals($expected, $result);
        }
    }

    /** @test */
    public function it_handles_migration_name_without_underscores_after_timestamp()
    {
        $filename = '2026_07_16_000002_simple';
        $expected = 'Simple';
        $this->assertEquals($expected, $this->repository->classNameFromMigration($filename));
    }

    /** @test */
    public function it_handles_migration_name_with_multiple_underscores_correctly()
    {
        $filename = '2026_07_16_000002_create_user_profiles_table';
        $expected = 'CreateUserProfilesTable';
        $this->assertEquals($expected, $this->repository->classNameFromMigration($filename));
    }

    /** @test */
    public function it_handles_empty_string_gracefully()
    {
        $filename = '';
        $this->assertEquals('', $this->repository->classNameFromMigration($filename));
    }
}
