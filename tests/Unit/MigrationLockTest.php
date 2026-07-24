<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Database\Migrations\MigrationLock;
use Reactor\Contracts\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;

class MigrationLockTest extends TestCase
{
    private string $tempDir;
    private LoggerInterface&MockObject $logger;
    private string $lockFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/reactor_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->lockFile = $this->tempDir . '/test.lock';
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    /** @test */
    public function it_acquires_lock_when_no_lock_file_exists()
    {
        $this->assertFileDoesNotExist($this->lockFile);

        $lock = new MigrationLock($this->logger, $this->lockFile);
        $lock->acquire();

        $this->assertFileExists($this->lockFile);
        $pid = (int) file_get_contents($this->lockFile);
        $this->assertEquals(getmypid(), $pid);
    }

    /** @test */
    public function it_throws_exception_when_lock_is_held_by_running_process()
    {
        // Simulate lock held by current process (or another process)
        file_put_contents($this->lockFile, (string) getmypid());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migration process already running');

        $lock = new MigrationLock($this->logger, $this->lockFile);
        $lock->acquire();
    }

    /** @test */
    public function it_removes_stale_lock_and_acquires()
    {
        // Stale lock with a PID that doesn't exist (e.g., 99999)
        file_put_contents($this->lockFile, '99999');

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Stale migration lock file found, removing', ['pid' => 99999]);

        $lock = new MigrationLock($this->logger, $this->lockFile);
        $lock->acquire();

        $this->assertFileExists($this->lockFile);
        $this->assertEquals(getmypid(), (int) file_get_contents($this->lockFile));
    }

    /** @test */
    public function it_releases_lock_only_if_owned_by_current_process()
    {
        file_put_contents($this->lockFile, (string) getmypid());

        $lock = new MigrationLock($this->logger, $this->lockFile);
        $lock->release();

        $this->assertFileDoesNotExist($this->lockFile);
    }

    /** @test */
    public function it_does_not_release_lock_if_owned_by_another_process()
    {
        $otherPid = 99999;
        file_put_contents($this->lockFile, (string) $otherPid);

        $lock = new MigrationLock($this->logger, $this->lockFile);
        $lock->release();

        // Lock file should remain (since current process PID doesn't match)
        $this->assertFileExists($this->lockFile);
        $this->assertEquals($otherPid, (int) file_get_contents($this->lockFile));
    }

    /** @test */
    public function it_creates_lock_directory_if_not_exists()
    {
        $deepLockFile = $this->tempDir . '/sub/dir/test.lock';
        $lock = new MigrationLock($this->logger, $deepLockFile);
        $lock->acquire();

        $this->assertFileExists($deepLockFile);
        $this->assertDirectoryExists(dirname($deepLockFile));

        // Cleanup
        unlink($deepLockFile);
        rmdir($this->tempDir . '/sub/dir');
        rmdir($this->tempDir . '/sub');
    }
}
